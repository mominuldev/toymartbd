#!/usr/bin/env bash
# Toy Mart BD — idempotent provisioning of the API + admin host (docs/ops/DEPLOYMENT.md §2).
#
#   sudo bash ops/provision/provision.sh                 provision / re-converge this host
#   bash ops/provision/provision.sh --render <dir>       render every config file into <dir>
#                                                        (no root, no changes — for review/tests)
#
# Target: a fresh Ubuntu 24.04 LTS VPS. Safe to re-run at any time: every step checks before it
# acts, config files are only replaced when their rendered content changes, generated secrets
# are created once and reused, and an existing shared/.env is never overwritten.
#
# Versions match CI (.github/workflows/ci.yml): PHP 8.4 with bcmath, intl, mbstring, pdo_mysql,
# gd (WebP + AVIF), exif, fileinfo — plus pcntl (Horizon), redis, zip, curl, xml; MySQL 8.4 LTS
# with utf8mb4_0900_ai_ci; Redis 7; Meilisearch pinned (MEILI_VERSION). No Node on the server:
# the admin bundle is built in CI and shipped inside the release artifact.
#
# Settings: /etc/toymartbd/provision.env (template: ops/provision/provision.env.example).

# Variables such as FPM_START_SERVERS are read indirectly by render() ({{NAME}} → ${!NAME}).
# shellcheck disable=SC2034

set -Eeuo pipefail
umask 0022

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
TEMPLATES=$HERE/templates
SETTINGS_FILE=${PROVISION_ENV:-/etc/toymartbd/provision.env}
GENERATED=/etc/toymartbd/generated.env

log() { printf '\n\033[1m[provision]\033[0m %s\n' "$*"; }
note() { printf '  - %s\n' "$*"; }
warn() { printf '  ! WARN: %s\n' "$*" >&2; WARNINGS+=("$*"); }
die() { printf '[provision] ERROR: %s\n' "$*" >&2; exit 1; }
WARNINGS=()

RENDER_DIR=
if [[ ${1:-} == --render ]]; then
    RENDER_DIR=${2:?usage: $0 --render <dir>}
    mkdir -p "$RENDER_DIR"
elif [[ -n ${1:-} ]]; then
    sed -n '4,6p' "$0" >&2
    exit 2
fi

# --- settings ------------------------------------------------------------------------------
if [[ -f $SETTINGS_FILE ]]; then
    # shellcheck source=/dev/null
    source "$SETTINGS_FILE"
fi
SERVER_NAMES=${SERVER_NAMES:-}
CERTBOT_EMAIL=${CERTBOT_EMAIL:-}
DEPLOY_SSH_PUBKEY=${DEPLOY_SSH_PUBKEY:-}
OWNER_SSH_PUBKEY=${OWNER_SSH_PUBKEY:-}
APP_ROOT=${APP_ROOT:-/var/www/toymartbd}
DEPLOY_USER=${DEPLOY_USER:-deploy}
DB_NAME=${DB_NAME:-toymartbd}
DB_USER=${DB_USER:-toymartbd}
SSH_PORT=${SSH_PORT:-22}
SWAP_SIZE_MB=${SWAP_SIZE_MB:-2048}
SSH_DISABLE_PASSWORD=${SSH_DISABLE_PASSWORD:-yes}
MEILI_VERSION=${MEILI_VERSION:-v1.15.2}
MEILI_SHA256=${MEILI_SHA256:-}
PHP_FPM_MAX_CHILDREN=${PHP_FPM_MAX_CHILDREN:-}
MYSQL_BUFFER_POOL=${MYSQL_BUFFER_POOL:-}
CLIENT_MAX_BODY=${CLIENT_MAX_BODY:-16m}

[[ -n $SERVER_NAMES ]] || die "SERVER_NAMES is empty — fill $SETTINGS_FILE (template: ops/provision/provision.env.example)"
read -r -a NAMES <<<"$SERVER_NAMES"
PRIMARY_DOMAIN=${NAMES[0]}
SERVER_NAMES_CSV=$(IFS=,; echo "${NAMES[*]}")
[[ $DEPLOY_USER =~ ^[a-z_][a-z0-9_-]*$ ]] || die "bad DEPLOY_USER"
[[ $MEILI_VERSION =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "MEILI_VERSION must look like v1.15.2"

# --- sizing from RAM ---------------------------------------------------------------------------
mem_mb() {
    if [[ -r /proc/meminfo ]]; then
        awk '/^MemTotal:/ {print int($2 / 1024)}' /proc/meminfo
    else
        echo 2048 # --render on a non-Linux machine: assume a 2 GB VPS
    fi
}
RAM_MB=$(mem_mb)
if [[ -z $MYSQL_BUFFER_POOL ]]; then
    MYSQL_BUFFER_POOL="$((RAM_MB / 4))M"
fi
if [[ -z $PHP_FPM_MAX_CHILDREN ]]; then
    # Reserve ~1 GB for MySQL, Redis, Meilisearch, Horizon and the OS; ~64 MB per PHP worker.
    PHP_FPM_MAX_CHILDREN=$(((RAM_MB - 1024) / 64))
fi
((PHP_FPM_MAX_CHILDREN < 4)) && PHP_FPM_MAX_CHILDREN=4
((PHP_FPM_MAX_CHILDREN > 32)) && PHP_FPM_MAX_CHILDREN=32
FPM_MAX_CHILDREN=$PHP_FPM_MAX_CHILDREN
FPM_MIN_SPARE=2
FPM_MAX_SPARE=$((FPM_MAX_CHILDREN / 2 > 2 ? FPM_MAX_CHILDREN / 2 : 3))
FPM_START_SERVERS=$(((FPM_MIN_SPARE + FPM_MAX_SPARE) / 2))
MEILI_INDEXING_MEMORY="$((RAM_MB >= 4096 ? 512 : 256)) MiB"

# --- rendering -------------------------------------------------------------------------------
# {{NAME}} → value of $NAME. Every placeholder must resolve; a leftover {{…}} is an error.
shopt -u patsub_replacement 2>/dev/null || true # bash 5.2: never treat & in a value as "the match"
render() {
    local template=$TEMPLATES/$1 out var
    out=$(<"$template")
    while [[ $out =~ \{\{([A-Z_][A-Z0-9_]*)\}\} ]]; do
        var=${BASH_REMATCH[1]}
        [[ -n ${!var+x} ]] || die "template $1 uses {{$var}}, which is not set"
        out=${out//"{{$var}}"/${!var}}
    done
    printf '%s\n' "$out"
}

CHANGED=()
# install_file <template> <dest> <mode> [owner]: write only when the content differs.
install_file() {
    local template=$1 dest=$2 mode=$3 owner=${4:-root:root} tmp
    if [[ -n $RENDER_DIR ]]; then
        render "$template" > "$RENDER_DIR/$(basename "$dest")"
        return 0
    fi
    tmp=$(mktemp)
    render "$template" > "$tmp"
    if [[ -f $dest ]] && cmp -s "$tmp" "$dest"; then
        rm -f "$tmp"
        return 0
    fi
    mkdir -p "$(dirname "$dest")"
    install -m "$mode" -o "${owner%%:*}" -g "${owner##*:}" "$tmp" "$dest"
    rm -f "$tmp"
    CHANGED+=("$dest")
    note "wrote $dest"
}
changed() { local f; for f in "${CHANGED[@]+"${CHANGED[@]}"}"; do [[ $f == "$1"* ]] && return 0; done; return 1; }

# --- secrets generated once, kept on the host --------------------------------------------------
gen_secret() { openssl rand -hex "${1:-24}"; }
load_or_generate_secrets() {
    install -d -m 0700 /etc/toymartbd
    touch "$GENERATED"
    chmod 0600 "$GENERATED"
    local name value
    for name in DB_PASSWORD REDIS_PASSWORD MEILI_MASTER_KEY REVALIDATE_SECRET BACKUP_ARCHIVE_PASSWORD APP_KEY; do
        value=$(grep -E "^$name=" "$GENERATED" | tail -n 1 | cut -d= -f2- || true)
        if [[ -z $value ]]; then
            case $name in
                APP_KEY) value="base64:$(openssl rand -base64 32)" ;;
                REVALIDATE_SECRET | BACKUP_ARCHIVE_PASSWORD) value=$(gen_secret 32) ;;
                *) value=$(gen_secret 24) ;;
            esac
            printf '%s=%s\n' "$name" "$value" >> "$GENERATED"
            note "generated $name (stored in $GENERATED)"
        fi
        printf -v "$name" '%s' "$value"
    done
}

if [[ -n $RENDER_DIR ]]; then
    # Placeholder secrets so every template renders; nothing real is written anywhere.
    DB_PASSWORD=render-db-password REDIS_PASSWORD=render-redis-password MEILI_MASTER_KEY=render-meili-key
    REVALIDATE_SECRET=render-revalidate-secret BACKUP_ARCHIVE_PASSWORD=render-archive-password
    APP_KEY=base64:cmVuZGVyLW9ubHktbm90LWEtcmVhbC1rZXktMDAwMDA=
    for t in nginx-http.conf:toymartbd-http.conf nginx-site.conf:toymartbd.conf nginx-site-http-only.conf:toymartbd-http-only.conf \
        php-fpm-pool.conf:toymartbd-pool.conf php-fpm.ini:99-toymartbd-fpm.ini php-cli.ini:99-toymartbd-cli.ini \
        mysql.cnf:zz-toymartbd.cnf meilisearch.toml:meilisearch.toml meilisearch.service:meilisearch.service \
        supervisor-horizon.conf:toymartbd-horizon.conf cron:toymartbd-cron logrotate:toymartbd-logrotate \
        fail2ban.local:toymartbd.local unattended-upgrades.conf:52toymartbd-unattended auto-upgrades.conf:20auto-upgrades \
        sudoers:toymartbd-deploy sshd.conf:60-toymartbd.conf deploy.env:deploy.env env.production.example:env.production; do
        install_file "${t%%:*}" "/${t##*:}" 0644
    done
    echo "rendered into $RENDER_DIR (RAM assumed ${RAM_MB} MB → FPM max_children $FPM_MAX_CHILDREN, buffer pool $MYSQL_BUFFER_POOL)"
    exit 0
fi

# --- preflight ---------------------------------------------------------------------------------
[[ $EUID -eq 0 ]] || die "run as root: sudo bash $0"
# shellcheck source=/dev/null
. /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]] || die "Ubuntu 24.04 LTS only (found ${PRETTY_NAME:-unknown})"
export DEBIAN_FRONTEND=noninteractive
APT_OPTS=(-y -q -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)
ARCH=$(dpkg --print-architecture)

log "Settings: ${SERVER_NAMES} · root ${APP_ROOT} · RAM ${RAM_MB} MB → FPM ${FPM_MAX_CHILDREN} workers, InnoDB pool ${MYSQL_BUFFER_POOL}"
load_or_generate_secrets

# --- base packages, swap, time -----------------------------------------------------------------
log "Base packages"
apt-get update -q
apt-get install "${APT_OPTS[@]}" ca-certificates curl gnupg software-properties-common unzip git \
    acl logrotate cron ufw fail2ban python3-systemd unattended-upgrades openssl tar
timedatectl set-timezone UTC 2>/dev/null || true # storage is UTC; Dhaka is applied in code

if ((SWAP_SIZE_MB > 0)) && ! swapon --show=NAME --noheadings | grep -q .; then
    log "Swap file (${SWAP_SIZE_MB} MB)"
    if [[ ! -f /swapfile ]]; then
        fallocate -l "${SWAP_SIZE_MB}M" /swapfile
        chmod 600 /swapfile
        mkswap /swapfile >/dev/null
    fi
    swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi
printf 'vm.swappiness=10\n' > /etc/sysctl.d/60-toymartbd.conf
sysctl -q -p /etc/sysctl.d/60-toymartbd.conf

# --- deploy user -------------------------------------------------------------------------------
log "Deploy user '$DEPLOY_USER'"
if ! id "$DEPLOY_USER" >/dev/null 2>&1; then
    adduser --disabled-password --gecos 'Toy Mart BD deploy' "$DEPLOY_USER"
fi
DEPLOY_HOME=$(getent passwd "$DEPLOY_USER" | cut -d: -f6)
install -d -m 0700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$DEPLOY_HOME/.ssh"
AUTH_KEYS=$DEPLOY_HOME/.ssh/authorized_keys
touch "$AUTH_KEYS"
for key in "$DEPLOY_SSH_PUBKEY" "$OWNER_SSH_PUBKEY"; do
    [[ -z $key ]] && continue
    grep -qxF "$key" "$AUTH_KEYS" || { echo "$key" >> "$AUTH_KEYS"; note "authorised a key for $DEPLOY_USER"; }
done
chown "$DEPLOY_USER:$DEPLOY_USER" "$AUTH_KEYS"
chmod 0600 "$AUTH_KEYS"
[[ -s $AUTH_KEYS ]] || warn "no SSH key for $DEPLOY_USER yet — set DEPLOY_SSH_PUBKEY and re-run, or GitHub cannot deploy"

# --- PHP 8.4 -----------------------------------------------------------------------------------
log "PHP 8.4 (ppa:ondrej/php — Ubuntu 24.04 ships 8.3)"
if ! grep -rqs 'ondrej/php' /etc/apt/sources.list.d/; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -q
fi
apt-get install "${APT_OPTS[@]}" php8.4-fpm php8.4-cli php8.4-common php8.4-bcmath php8.4-intl \
    php8.4-mbstring php8.4-mysql php8.4-sqlite3 php8.4-gd php8.4-xml php8.4-curl php8.4-zip \
    php8.4-redis php8.4-opcache php8.4-readline composer
update-alternatives --set php /usr/bin/php8.4 >/dev/null

missing=()
modules=$(php8.4 -m)
for ext in bcmath intl mbstring pdo_mysql gd exif fileinfo pcntl zip curl dom openssl sodium redis 'Zend OPcache'; do
    grep -qix "$ext" <<<"$modules" || missing+=("$ext")
done
((${#missing[@]} == 0)) || die "PHP extensions missing: ${missing[*]}"
# Same gate as CI: the media pipeline needs GD to ENCODE WebP and AVIF (config/media.php).
# shellcheck disable=SC2016 # PHP code, single-quoted on purpose
php8.4 -r '$i = gd_info(); exit(!empty($i["WebP Support"]) && !empty($i["AVIF Support"]) && function_exists("exif_read_data") ? 0 : 1);' \
    || die "php8.4-gd lacks WebP or AVIF support on this host — the media pipeline cannot run"
note "PHP $(php8.4 -r 'echo PHP_VERSION;') with every required extension; GD encodes WebP + AVIF"

install_file php-fpm.ini /etc/php/8.4/fpm/conf.d/99-toymartbd.ini 0644
install_file php-cli.ini /etc/php/8.4/cli/conf.d/99-toymartbd.ini 0644
install_file php-fpm-pool.conf /etc/php/8.4/fpm/pool.d/toymartbd.conf 0644
if [[ -f /etc/php/8.4/fpm/pool.d/www.conf ]]; then
    mv /etc/php/8.4/fpm/pool.d/www.conf /etc/php/8.4/fpm/pool.d/www.conf.disabled
    CHANGED+=(/etc/php/8.4/fpm/pool.d/www.conf)
    note "disabled the default www pool"
fi
php-fpm8.4 -t >/dev/null 2>&1 || { php-fpm8.4 -t; die "php-fpm config test failed"; }
systemctl enable --now php8.4-fpm >/dev/null
changed /etc/php/8.4/fpm && systemctl restart php8.4-fpm

# --- MySQL 8.4 LTS -----------------------------------------------------------------------------
log "MySQL 8.4 LTS (repo.mysql.com — Ubuntu 24.04 ships 8.0)"
if [[ ! -f /etc/apt/sources.list.d/mysql.list ]]; then
    install -d -m 0755 /etc/apt/keyrings
    curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 -o /etc/apt/keyrings/mysql.asc
    echo "deb [signed-by=/etc/apt/keyrings/mysql.asc] http://repo.mysql.com/apt/ubuntu noble mysql-8.4-lts" \
        > /etc/apt/sources.list.d/mysql.list
    apt-get update -q
fi
# Blank root password → root authenticates by unix socket only (sudo mysql); no root password exists.
echo "mysql-community-server mysql-community-server/root-pass password " | debconf-set-selections
echo "mysql-community-server mysql-community-server/re-root-pass password " | debconf-set-selections
apt-get install "${APT_OPTS[@]}" mysql-server mysql-client
install_file mysql.cnf /etc/mysql/mysql.conf.d/zz-toymartbd.cnf 0644
systemctl enable --now mysql >/dev/null
changed /etc/mysql && systemctl restart mysql
mysql_version=$(mysql -N -e 'SELECT VERSION()')
[[ $mysql_version == 8.4.* ]] || die "expected MySQL 8.4, found $mysql_version"
[[ $(mysql -N -e 'SELECT @@collation_server') == utf8mb4_0900_ai_ci ]] || die "collation_server is not utf8mb4_0900_ai_ci"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
-- toymart:backup:restore-rehearsal restores into its own scratch database (backup-and-restore.md).
GRANT ALL PRIVILEGES ON \`toymartbd_restore_check\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
note "MySQL $mysql_version, utf8mb4_0900_ai_ci, database '$DB_NAME' and user '$DB_USER'"

# --- Redis -------------------------------------------------------------------------------------
log "Redis"
apt-get install "${APT_OPTS[@]}" redis-server
set_redis() { # key value — replace the directive (commented or not) or append it
    local conf=/etc/redis/redis.conf
    if grep -Eq "^#?[[:space:]]*$1 " "$conf"; then
        if ! grep -Eqx "$1 $2" "$conf"; then
            sed -i -E "s|^#?[[:space:]]*$1 .*|$1 $2|" "$conf"
            CHANGED+=(/etc/redis/redis.conf)
        fi
    else
        echo "$1 $2" >> "$conf"
        CHANGED+=(/etc/redis/redis.conf)
    fi
}
set_redis bind "127.0.0.1 -::1"
set_redis protected-mode yes
set_redis supervised systemd
set_redis requirepass "$REDIS_PASSWORD"
# Queues and sessions live here: never evict a key to make room.
set_redis maxmemory-policy noeviction
set_redis maxmemory "$((RAM_MB / 8))mb"
chmod 0640 /etc/redis/redis.conf
systemctl enable --now redis-server >/dev/null
changed /etc/redis && systemctl restart redis-server
REDISCLI_AUTH=$REDIS_PASSWORD redis-cli ping | grep -q PONG || die "redis does not answer PING"
note "Redis $(redis-server --version | grep -oE 'v=[0-9.]+' | cut -d= -f2), password-protected, localhost only"

# --- Meilisearch -------------------------------------------------------------------------------
log "Meilisearch $MEILI_VERSION"
id meilisearch >/dev/null 2>&1 || useradd --system --home /var/lib/meilisearch --shell /usr/sbin/nologin meilisearch
install -d -m 0750 -o meilisearch -g meilisearch /var/lib/meilisearch
case $ARCH in
    amd64) meili_asset=meilisearch-linux-amd64 ;;
    arm64) meili_asset=meilisearch-linux-aarch64 ;;
    *) die "no Meilisearch build for $ARCH" ;;
esac
meili_bin=/usr/local/bin/meilisearch-$MEILI_VERSION
if [[ ! -x $meili_bin ]]; then
    tmp=$(mktemp)
    curl -fsSL "https://github.com/meilisearch/meilisearch/releases/download/$MEILI_VERSION/$meili_asset" -o "$tmp"
    sum=$(sha256sum "$tmp" | cut -d' ' -f1)
    if [[ -n $MEILI_SHA256 && $sum != "$MEILI_SHA256" ]]; then
        rm -f "$tmp"
        die "Meilisearch checksum mismatch: got $sum, expected $MEILI_SHA256"
    fi
    [[ -z $MEILI_SHA256 ]] && warn "Meilisearch binary not checksum-pinned; record MEILI_SHA256=$sum in provision.env"
    install -m 0755 "$tmp" "$meili_bin"
    rm -f "$tmp"
fi
current_meili=$(readlink /usr/local/bin/meilisearch 2>/dev/null || true)
if [[ -n $current_meili && $current_meili != "$meili_bin" && -d /var/lib/meilisearch/data ]]; then
    warn "Meilisearch version change ($current_meili → $meili_bin) on existing data: see DEPLOYMENT.md §8 (stop, move data aside, start, reindex). Not switched."
else
    ln -sfn "$meili_bin" /usr/local/bin/meilisearch
fi
if [[ ! -f /etc/meilisearch.env ]] || ! grep -qx "MEILI_MASTER_KEY=$MEILI_MASTER_KEY" /etc/meilisearch.env; then
    install -m 0600 /dev/null /etc/meilisearch.env
    echo "MEILI_MASTER_KEY=$MEILI_MASTER_KEY" > /etc/meilisearch.env
    CHANGED+=(/etc/meilisearch.env)
fi
install_file meilisearch.toml /etc/meilisearch.toml 0644
install_file meilisearch.service /etc/systemd/system/meilisearch.service 0644
systemctl daemon-reload
systemctl enable --now meilisearch >/dev/null
if changed /etc/meilisearch || changed /etc/systemd/system/meilisearch.service; then systemctl restart meilisearch; fi
for _ in $(seq 1 20); do curl -fsS http://127.0.0.1:7700/health >/dev/null 2>&1 && break; sleep 1; done
curl -fsS http://127.0.0.1:7700/health | grep -q available || die "Meilisearch is not healthy (journalctl -u meilisearch)"
note "Meilisearch $(/usr/local/bin/meilisearch --version 2>/dev/null | head -n1) on 127.0.0.1:7700"

# --- application layout -------------------------------------------------------------------------
log "Application layout under $APP_ROOT"
install -d -m 0755 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_ROOT" "$APP_ROOT/releases" "$APP_ROOT/shared" \
    "$APP_ROOT/shared/storage" "$APP_ROOT/bin" "$APP_ROOT/artifacts"
for d in app/private app/public framework/cache/data framework/sessions framework/views logs; do
    install -d -m 0775 -o "$DEPLOY_USER" -g "$DEPLOY_USER" "$APP_ROOT/shared/storage/$d"
done
install -d -m 0750 -o "$DEPLOY_USER" -g adm /var/log/toymartbd
install -d -m 0755 /var/www/letsencrypt
install_file deploy.env "$APP_ROOT/deploy.env" 0644 "$DEPLOY_USER:$DEPLOY_USER"
if [[ ! -f $APP_ROOT/shared/.env ]]; then
    install_file env.production.example "$APP_ROOT/shared/.env" 0600 "$DEPLOY_USER:$DEPLOY_USER"
    warn "created $APP_ROOT/shared/.env — fill every CHANGE_ME before the first deploy"
elif grep -q CHANGE_ME "$APP_ROOT/shared/.env"; then
    warn "$APP_ROOT/shared/.env still has CHANGE_ME values; deploys refuse to run until they are filled"
fi

# --- Horizon (Supervisor), scheduler (cron), log rotation, sudo ----------------------------------
log "Supervisor (Horizon), cron (scheduler), logrotate, sudoers"
apt-get install "${APT_OPTS[@]}" supervisor
install_file supervisor-horizon.conf /etc/supervisor/conf.d/toymartbd-horizon.conf 0644
systemctl enable --now supervisor >/dev/null
if changed /etc/supervisor; then
    supervisorctl reread >/dev/null
    # Before the first deploy there is no `current` yet, so Horizon cannot start; the first
    # deploy starts it (HORIZON_ENSURE_CMD in deploy.env).
    supervisorctl update >/dev/null 2>&1 || note "Horizon not started yet (no release deployed)"
fi
install_file cron /etc/cron.d/toymartbd 0644
install_file logrotate /etc/logrotate.d/toymartbd 0644
logrotate -d /etc/logrotate.d/toymartbd >/dev/null 2>&1 || warn "logrotate -d reported a problem with /etc/logrotate.d/toymartbd"
tmp=$(mktemp)
render sudoers > "$tmp"
visudo -cqf "$tmp" || die "rendered sudoers is invalid"
rm -f "$tmp"
install_file sudoers /etc/sudoers.d/toymartbd-deploy 0440

# --- nginx + TLS -------------------------------------------------------------------------------
log "nginx + Let's Encrypt"
apt-get install "${APT_OPTS[@]}" nginx certbot
rm -f /etc/nginx/sites-enabled/default
install_file nginx-http.conf /etc/nginx/conf.d/toymartbd-http.conf 0644
CERT=/etc/letsencrypt/live/$PRIMARY_DOMAIN/fullchain.pem
write_site() {
    if [[ -f $CERT ]]; then
        install_file nginx-site.conf /etc/nginx/sites-available/toymartbd.conf 0644
    else
        install_file nginx-site-http-only.conf /etc/nginx/sites-available/toymartbd.conf 0644
    fi
    ln -sfn /etc/nginx/sites-available/toymartbd.conf /etc/nginx/sites-enabled/toymartbd.conf
    nginx -t -q || die "nginx -t failed"
    systemctl enable --now nginx >/dev/null
    systemctl reload nginx
}
write_site
if [[ ! -f $CERT ]]; then
    if [[ -z $CERTBOT_EMAIL ]]; then
        warn "CERTBOT_EMAIL is empty — no TLS certificate requested; the site answers 503 on HTTP until it is"
    else
        cert_args=()
        for n in "${NAMES[@]}"; do cert_args+=(-d "$n"); done
        if certbot certonly --webroot -w /var/www/letsencrypt "${cert_args[@]}" --email "$CERTBOT_EMAIL" \
            --agree-tos --non-interactive --keep-until-expiring --deploy-hook 'systemctl reload nginx'; then
            write_site
            note "TLS certificate issued for ${NAMES[*]}"
        else
            warn "certbot failed — does DNS for ${NAMES[*]} point at this server yet? Re-run this script when it does."
        fi
    fi
fi
systemctl list-timers certbot.timer --no-legend 2>/dev/null | grep -q certbot || systemctl enable --now certbot.timer >/dev/null 2>&1 || true

# --- firewall, fail2ban, SSH, unattended upgrades ------------------------------------------------
log "UFW, fail2ban, SSH hardening, unattended security upgrades"
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw limit "$SSH_PORT/tcp" >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null
install_file fail2ban.local /etc/fail2ban/jail.d/toymartbd.local 0644
systemctl enable --now fail2ban >/dev/null
changed /etc/fail2ban && systemctl restart fail2ban

key_login_exists() {
    local f
    [[ -s /root/.ssh/authorized_keys ]] && return 0
    for f in /home/*/.ssh/authorized_keys; do
        [[ -s $f ]] || continue
        local u
        u=$(stat -c %U "$f")
        [[ $u != "$DEPLOY_USER" ]] && id -nG "$u" | grep -qw sudo && return 0
    done
    return 1
}
if [[ $SSH_DISABLE_PASSWORD == yes ]]; then
    if key_login_exists; then
        install_file sshd.conf /etc/ssh/sshd_config.d/60-toymartbd.conf 0644
        if changed /etc/ssh; then
            sshd -t || die "sshd -t failed — not reloading"
            systemctl reload ssh
        fi
    else
        warn "SSH password login left ON: no key login for root or a sudo user yet (add one, then re-run)"
    fi
fi

install_file unattended-upgrades.conf /etc/apt/apt.conf.d/52toymartbd-unattended 0644
install_file auto-upgrades.conf /etc/apt/apt.conf.d/20auto-upgrades 0644
systemctl enable --now unattended-upgrades >/dev/null

# --- summary -------------------------------------------------------------------------------------
log "Done"
note "PHP $(php8.4 -r 'echo PHP_VERSION;') · MySQL $mysql_version · Redis · Meilisearch $MEILI_VERSION · nginx $(nginx -v 2>&1 | cut -d/ -f2)"
note "deploy target: $DEPLOY_USER@$(hostname -f 2>/dev/null || hostname):$APP_ROOT"
note "generated secrets: $GENERATED (root only). Escrow APP_KEY and BACKUP_ARCHIVE_PASSWORD now."
note "storefront revalidate secret (for Vercel): grep REVALIDATE_SECRET $GENERATED"
[[ -f /var/run/reboot-required ]] && warn "a reboot is pending (kernel update) — pick a quiet hour"
if ((${#WARNINGS[@]})); then
    printf '\n%s\n' "Warnings:"
    printf '  - %s\n' "${WARNINGS[@]}"
fi
