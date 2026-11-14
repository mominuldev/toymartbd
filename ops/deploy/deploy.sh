#!/usr/bin/env bash
# Toy Mart BD — zero-downtime release and rollback for the API + admin host.
# docs/ops/DEPLOYMENT.md · SYSTEM-ARCHITECTURE.md §12.
#
# Runs ON THE SERVER as the non-root deploy user. The GitHub deploy workflow copies this file
# to $APP_ROOT/bin/toymart-deploy on every deploy, so the server always runs the version that
# matches the release it is installing.
#
#   toymart-deploy release <artifact.tar.gz> <sha>   install, migrate, swap, health-check
#   toymart-deploy rollback [<sha>]                  previous release (or the one named)
#   toymart-deploy list                              releases, which is live, history
#   toymart-deploy health                            run the post-swap health check only
#
# Layout under APP_ROOT (default /var/www/toymartbd):
#
#   releases/<sha>/      one directory per release (code + vendor + caches)
#   shared/.env          the owner's secrets — never in the repo, never in an artifact
#   shared/storage/      uploads, logs, sessions, maintenance flag — survives every release
#   current -> releases/<sha>    the ONLY thing nginx, PHP-FPM, Horizon and cron point at
#   releases.log         append-only history: "<utc time> deployed|rolled-back-to|failed <sha>"
#   deploy.env           optional non-secret overrides for the variables below
#
# Migration policy (DEPLOYMENT.md §5): migrations run BEFORE the swap, while the old code still
# serves — expand-and-contract (SYSTEM-ARCHITECTURE §12) makes the old code compatible with the
# new schema. `php artisan down` happens only when a PENDING migration file carries the marker
# line `@deploy:destructive` (or DEPLOY_FORCE_MAINTENANCE=1). Rollback NEVER rolls schema back.

set -Eeuo pipefail
umask 0022

readonly DESTRUCTIVE_MARKER='@deploy:destructive'

APP_ROOT=${APP_ROOT:-/var/www/toymartbd}

# deploy.env holds KEY=VALUE lines; a variable already set in the environment wins.
load_deploy_env() {
    local file=$APP_ROOT/deploy.env line key value
    [[ -f $file ]] || return 0
    while IFS= read -r line || [[ -n $line ]]; do
        [[ $line =~ ^[[:space:]]*(#|$) ]] && continue
        [[ $line =~ ^[[:space:]]*([A-Z_][A-Z0-9_]*)=(.*)$ ]] || continue
        key=${BASH_REMATCH[1]}
        value=${BASH_REMATCH[2]}
        value=${value%\"}; value=${value#\"}; value=${value%\'}; value=${value#\'}
        if [[ -z ${!key+x} ]]; then
            export "$key=$value"
        fi
    done < "$file"
}
load_deploy_env

PHP_BIN=${PHP_BIN:-php}
COMPOSER_BIN=${COMPOSER_BIN:-composer}
COMPOSER_FLAGS=${COMPOSER_FLAGS:---no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --prefer-dist}
KEEP_RELEASES=${KEEP_RELEASES:-5}
MIN_FREE_MB=${MIN_FREE_MB:-1024}
# Reload PHP-FPM so it resolves the new `current` and drops its opcache (validate_timestamps=0).
RELOAD_CMD=${RELOAD_CMD:-sudo -n /usr/bin/systemctl reload php8.4-fpm}
HORIZON_TERMINATE=${HORIZON_TERMINATE:-1}
# Starts Horizon under Supervisor if it is not running (first deploy). Empty = skip.
HORIZON_ENSURE_CMD=${HORIZON_ENSURE_CMD:-}
MAINTENANCE_RETRY=${MAINTENANCE_RETRY:-60}
DEPLOY_FORCE_MAINTENANCE=${DEPLOY_FORCE_MAINTENANCE:-0}
# Health: default is APP_URL from shared/.env + /api/v1/health, resolved to this host
# (HEALTH_RESOLVE="api.example.com:443:127.0.0.1") so the check never leaves the machine.
HEALTH_URL=${HEALTH_URL:-}
HEALTH_RESOLVE=${HEALTH_RESOLVE:-}
HEALTH_RETRIES=${HEALTH_RETRIES:-10}
HEALTH_INTERVAL=${HEALTH_INTERVAL:-3}
HEALTH_TIMEOUT=${HEALTH_TIMEOUT:-10}
# Extra curl arguments, word-split (e.g. "--cacert /path/ca.pem" for a private CA).
HEALTH_CURL_OPTS=${HEALTH_CURL_OPTS:-}

RELEASES=$APP_ROOT/releases
SHARED=$APP_ROOT/shared
CURRENT=$APP_ROOT/current
HISTORY=$APP_ROOT/releases.log
LOCK=$APP_ROOT/.deploy.lock

log() { printf '[deploy %s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
warn() { printf '[deploy %s] WARN: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { printf '[deploy %s] ERROR: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; exit 1; }

usage() {
    sed -n '9,12p' "$0" | sed 's/^# \{0,1\}//'
    exit 2
}

valid_id() { [[ $1 =~ ^[0-9a-f]{7,40}$ ]]; }

# --- lock --------------------------------------------------------------------------------
release_lock() { rm -rf "$LOCK"; }
take_lock() {
    if ! mkdir "$LOCK" 2>/dev/null; then
        local pid
        pid=$(cat "$LOCK/pid" 2>/dev/null || true)
        if [[ -n $pid ]] && kill -0 "$pid" 2>/dev/null; then
            die "another deploy is running (pid $pid). Wait for it; never run two at once."
        fi
        warn "removing a stale lock left by pid ${pid:-unknown}"
        rm -rf "$LOCK"
        mkdir "$LOCK" || die "could not take the deploy lock at $LOCK"
    fi
    echo $$ > "$LOCK/pid"
}

# --- helpers -----------------------------------------------------------------------------
current_id() {
    [[ -L $CURRENT ]] || return 0
    local target
    target=$(readlink "$CURRENT")
    basename "$target"
}

record() { printf '%s %s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$1" "$2" >> "$HISTORY"; }

artisan() {
    local dir=$1
    shift
    (cd "$dir" && "$PHP_BIN" artisan "$@")
}

# Atomic switch: build the new link beside `current`, then rename(2) over it. `ln -sfn` is
# unlink + symlink — two syscalls with a gap in which nginx would 404. perl's rename is the
# portable way to get rename(2) (GNU `mv -T` does not exist on BSD).
swap_to() {
    local id=$1 tmp=$APP_ROOT/.current.$$
    ln -s "releases/$id" "$tmp"
    perl -e 'rename($ARGV[0], $ARGV[1]) or die "rename: $!\n"' "$tmp" "$CURRENT"
    log "current -> releases/$id"
}

reload_php() {
    [[ -n $RELOAD_CMD ]] || return 0
    log "reloading PHP: $RELOAD_CMD"
    bash -c "$RELOAD_CMD"
}

restart_horizon() {
    if [[ -n $HORIZON_ENSURE_CMD ]]; then
        bash -c "$HORIZON_ENSURE_CMD" >/dev/null 2>&1 || true # "already started" is fine
    fi
    [[ $HORIZON_TERMINATE == 1 ]] || return 0
    # Horizon finishes its in-flight jobs, exits, and Supervisor starts it again from `current`.
    if artisan "$CURRENT/" horizon:terminate --no-interaction; then
        log "horizon:terminate sent; Supervisor restarts Horizon from current"
    else
        warn "horizon:terminate failed — is Horizon running? Check: sudo supervisorctl status"
    fi
}

app_url_from_env() {
    local line
    line=$(grep -E '^APP_URL=' "$SHARED/.env" 2>/dev/null | tail -n 1 || true)
    line=${line#APP_URL=}
    line=${line%\"}; line=${line#\"}
    printf '%s' "${line%/}"
}

health_check() {
    local url=$HEALTH_URL
    if [[ -z $url ]]; then
        url="$(app_url_from_env)/api/v1/health"
    fi
    [[ $url =~ ^https?:// ]] || die "no health URL: set APP_URL in $SHARED/.env or HEALTH_URL in deploy.env"

    local args=(--silent --show-error --max-time "$HEALTH_TIMEOUT" -o "$APP_ROOT/.health.body" -w '%{http_code}')
    if [[ -n $HEALTH_RESOLVE ]]; then
        args+=(--resolve "$HEALTH_RESOLVE")
    fi
    if [[ -n $HEALTH_CURL_OPTS ]]; then
        local -a extra
        read -r -a extra <<<"$HEALTH_CURL_OPTS"
        args+=("${extra[@]}")
    fi

    local attempt code
    for ((attempt = 1; attempt <= HEALTH_RETRIES; attempt++)); do
        code=$(curl "${args[@]}" "$url" 2>/dev/null || true)
        if [[ $code == 200 ]]; then
            log "health OK ($url, attempt $attempt)"
            rm -f "$APP_ROOT/.health.body"
            return 0
        fi
        warn "health attempt $attempt/$HEALTH_RETRIES: HTTP ${code:-none} from $url"
        sleep "$HEALTH_INTERVAL"
    done
    # The endpoint is public and reports only up/down per dependency, so the body is safe to print.
    if [[ -s $APP_ROOT/.health.body ]]; then
        warn "last health body: $(head -c 600 "$APP_ROOT/.health.body")"
    fi
    rm -f "$APP_ROOT/.health.body"
    return 1
}

# The most recent successfully deployed release before the live one that still exists.
previous_id() {
    local live=$1
    [[ -f $HISTORY ]] || return 0
    local -a deployed=()
    local _ts event id
    while read -r _ts event id; do
        [[ $event == deployed ]] && deployed+=("$id")
    done < "$HISTORY"

    local i start=$((${#deployed[@]} - 1))
    # Start below the last time the live release was deployed.
    for ((i = ${#deployed[@]} - 1; i >= 0; i--)); do
        if [[ ${deployed[i]} == "$live" ]]; then
            start=$((i - 1))
            break
        fi
    done
    for ((i = start; i >= 0; i--)); do
        if [[ ${deployed[i]} != "$live" && -d $RELEASES/${deployed[i]} ]]; then
            printf '%s' "${deployed[i]}"
            return 0
        fi
    done
}

ensure_shared() {
    [[ -f $SHARED/.env ]] || die "$SHARED/.env is missing. Fill it from ops/provision/templates/env.production.example (DEPLOYMENT.md §3)."
    grep -Eq '^APP_KEY=base64:.+' "$SHARED/.env" || die "APP_KEY is empty in $SHARED/.env (php artisan key:generate --show, then escrow it)."
    local unfilled
    unfilled=$(grep -E '^[A-Z0-9_]+=.*CHANGE_ME' "$SHARED/.env" | cut -d= -f1 | tr '\n' ' ' || true)
    [[ -z $unfilled ]] || die "$SHARED/.env still has CHANGE_ME values: $unfilled"
    local d
    for d in app/private app/public framework/cache/data framework/sessions framework/views logs; do
        mkdir -p "$SHARED/storage/$d"
    done
}

check_disk() {
    local free_mb
    free_mb=$(df -Pk "$APP_ROOT" | awk 'NR==2 {print int($4 / 1024)}')
    if ((free_mb < MIN_FREE_MB)); then
        die "only ${free_mb} MB free under $APP_ROOT (need $MIN_FREE_MB). Lower KEEP_RELEASES or free space."
    fi
}

# Pending migration files that carry the destructive marker. Echoes their names.
destructive_pending() {
    local dir=$1 status names=() name file
    if status=$(artisan "$dir" migrate:status --pending --no-ansi 2>/dev/null); then
        while read -r name; do names+=("$name"); done < <(grep -oE '[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[A-Za-z0-9_]+' <<<"$status" || true)
    else
        # No migrations table yet (first deploy): every migration is pending.
        for file in "$dir"/database/migrations/*.php; do
            [[ -e $file ]] && names+=("$(basename "$file" .php)")
        done
    fi
    for name in "${names[@]+"${names[@]}"}"; do
        file=$dir/database/migrations/$name.php
        if [[ -f $file ]] && grep -q -- "$DESTRUCTIVE_MARKER" "$file"; then
            printf '%s\n' "$name"
        fi
    done
}

prune() {
    local live=$1 keep_also=$2 count=0 dir id
    # Newest first by activation time (the release directory is touched when it goes live).
    while IFS= read -r dir; do
        id=$(basename "$dir")
        count=$((count + 1))
        if ((count <= KEEP_RELEASES)) || [[ $id == "$live" || $id == "$keep_also" ]]; then
            continue
        fi
        log "pruning old release $id"
        chmod -R u+w "$dir" 2>/dev/null || true
        rm -rf "$dir"
    done < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d ! -name '.*' -exec ls -1dt {} + 2>/dev/null)
}

# --- release -----------------------------------------------------------------------------
STAGE=none
NEW_ID=
PREV_ID=
WENT_DOWN=0

# EXIT handler for `release`: `set -e` and `die` both end up here with a non-zero status.
on_release_exit() {
    local status=$?
    trap - EXIT
    if ((status != 0)); then
        case $STAGE in
            prepare)
                warn "release $NEW_ID failed before anything went live; the old release keeps serving."
                if [[ -n $NEW_ID && -d $RELEASES/$NEW_ID && $NEW_ID != "$(current_id)" ]]; then
                    chmod -R u+w "$RELEASES/$NEW_ID" 2>/dev/null || true
                    rm -rf "${RELEASES:?}/$NEW_ID"
                fi
                rm -rf "$RELEASES"/.incoming-*-$$
                record failed "$NEW_ID"
                ;;
            migrate)
                warn "MIGRATION FAILED for $NEW_ID. The old release (${PREV_ID:-none}) is still live."
                warn "MySQL DDL is not transactional: a multi-step migration may be HALF APPLIED."
                warn "Inspect with: cd $CURRENT && php artisan migrate:status"
                if [[ $WENT_DOWN == 1 ]]; then
                    warn "Maintenance mode is LEFT ON on purpose (destructive migration). After checking the schema: cd $CURRENT && php artisan up"
                fi
                record failed "$NEW_ID"
                ;;
            live)
                warn "release $NEW_ID failed after the swap; rolling back to ${PREV_ID:-nothing}."
                auto_rollback || true
                ;;
        esac
    fi
    release_lock
    exit "$status"
}

auto_rollback() {
    record failed "$NEW_ID"
    if [[ -z $PREV_ID ]]; then
        warn "first release: there is nothing to roll back to. current still points at $NEW_ID."
        if [[ $WENT_DOWN == 1 ]]; then artisan "$CURRENT/" up || true; fi
        return 0
    fi
    swap_to "$PREV_ID"
    reload_php || warn "PHP reload failed during rollback — run it by hand: $RELOAD_CMD"
    if [[ $WENT_DOWN == 1 ]]; then
        artisan "$CURRENT/" up || warn "php artisan up failed"
    fi
    restart_horizon
    warn "ROLLED BACK to $PREV_ID. Schema changes from $NEW_ID stay applied (they are not reversed)."
    if health_check; then
        log "previous release $PREV_ID is healthy again"
    else
        warn "the previous release is ALSO unhealthy — likely a dependency (database, Redis, Meilisearch), not the code."
    fi
}

cmd_release() {
    local artifact=${1:-} id=${2:-}
    [[ -n $artifact && -n $id ]] || usage
    valid_id "$id" || die "release id must be a git sha (7–40 hex chars), got '$id'"
    [[ -f $artifact ]] || die "artifact not found: $artifact"

    mkdir -p "$RELEASES" "$SHARED"
    take_lock
    trap on_release_exit EXIT

    NEW_ID=$id
    PREV_ID=$(current_id)
    if [[ $PREV_ID == "$id" ]]; then
        log "$id is already live; nothing to do."
        return 0
    fi

    STAGE=prepare
    ensure_shared
    check_disk

    local dir=$RELEASES/$id staging=$RELEASES/.incoming-$id-$$
    log "preparing release $id (live: ${PREV_ID:-none})"
    rm -rf "$staging"
    mkdir -p "$staging"
    tar -xzf "$artifact" -C "$staging"
    [[ -f $staging/artisan ]] || die "artifact has no artisan — not a Laravel build"
    [[ -f $staging/public/build/manifest.json ]] || die "artifact has no admin bundle (public/build/manifest.json); it is built in CI, never on the server"
    if [[ -f $staging/REVISION ]] && [[ $(<"$staging/REVISION") != "$id"* ]]; then
        die "artifact REVISION $(<"$staging/REVISION") does not match $id"
    fi
    if [[ -d $dir ]]; then
        log "replacing an earlier, non-live copy of $id"
        chmod -R u+w "$dir" 2>/dev/null || true
        rm -rf "$dir"
    fi
    mv "$staging" "$dir"

    # Shared state: one .env and one storage tree for every release.
    rm -rf "$dir/storage"
    ln -s "$SHARED/storage" "$dir/storage"
    ln -sfn "$SHARED/.env" "$dir/.env"
    mkdir -p "$dir/bootstrap/cache"
    rm -f "$dir"/bootstrap/cache/*.php

    log "composer install $COMPOSER_FLAGS"
    # shellcheck disable=SC2086 # COMPOSER_FLAGS is a deliberate word list
    (cd "$dir" && "$COMPOSER_BIN" install $COMPOSER_FLAGS)

    artisan "$dir" storage:link --force --no-interaction >/dev/null
    # SENTRY_RELEASE from the process environment wins over .env and is baked into the cached
    # config, so web, Horizon and the scheduler all report this release's sha.
    SENTRY_RELEASE=$id artisan "$dir" config:cache --no-interaction
    artisan "$dir" route:cache --no-interaction
    artisan "$dir" view:cache --no-interaction
    artisan "$dir" event:cache --no-interaction

    local destructive name
    destructive=$(destructive_pending "$dir")

    STAGE=migrate
    if [[ -n $destructive || $DEPLOY_FORCE_MAINTENANCE == 1 ]]; then
        if [[ -n $destructive ]]; then
            log "destructive migration(s) pending — maintenance mode for the migration window:"
            while IFS= read -r name; do log "    $name"; done <<<"$destructive"
        else
            log "DEPLOY_FORCE_MAINTENANCE=1 — maintenance mode for the migration window"
        fi
        artisan "$dir" down --retry="$MAINTENANCE_RETRY" --no-interaction
        WENT_DOWN=1
    fi
    artisan "$dir" migrate --force --no-interaction

    STAGE=live
    swap_to "$id"
    touch "$dir"
    reload_php
    if [[ $WENT_DOWN == 1 ]]; then
        artisan "$CURRENT/" up --no-interaction
    fi

    if ! health_check; then
        warn "health check failed for $id"
        STAGE=rolled-back
        auto_rollback
        exit 1
    fi

    restart_horizon
    record deployed "$id"
    STAGE=finished
    rm -f "$artifact"
    prune "$id" "$PREV_ID"
    log "release $id is live."
}

# --- rollback ----------------------------------------------------------------------------
cmd_rollback() {
    local target=${1:-} live
    take_lock
    trap 'release_lock' EXIT
    live=$(current_id)
    [[ -n $live ]] || die "no current release"
    if [[ -z $target ]]; then
        target=$(previous_id "$live")
        [[ -n $target ]] || die "no earlier release to roll back to (see: toymart-deploy list)"
    fi
    valid_id "$target" || die "not a release id: $target"
    [[ $target != "$live" ]] || die "$target is already live"
    [[ -f $RELEASES/$target/vendor/autoload.php ]] || die "release $target is missing or incomplete"

    ensure_shared
    log "rolling back $live -> $target"
    # Re-cache config: shared/.env may have changed since that release was built.
    SENTRY_RELEASE=$target artisan "$RELEASES/$target" config:cache --no-interaction
    swap_to "$target"
    touch "$RELEASES/$target"
    reload_php
    restart_horizon
    record rolled-back-to "$target"
    cat <<EOF

  Code is now $target. The DATABASE SCHEMA WAS NOT ROLLED BACK — migrations from $live stay
  applied. That is safe because every schema change is expand-and-contract: the previous
  release never depends on a column or table the newer one removed (DEPLOYMENT.md §5).
  If $live ran a migration marked $DESTRUCTIVE_MARKER, check the data before trusting $target.

EOF
    if ! health_check; then
        die "rolled back to $target, but it is NOT healthy. Check dependencies: database, Redis, Meilisearch."
    fi
    log "rollback complete: $target is live."
}

cmd_list() {
    local live dir
    live=$(current_id)
    echo "current: ${live:-none}"
    echo "releases (newest first):"
    while IFS= read -r dir; do
        local id
        id=$(basename "$dir")
        if [[ $id == "$live" ]]; then echo "  * $id"; else echo "    $id"; fi
    done < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d ! -name '.*' -exec ls -1dt {} + 2>/dev/null)
    if [[ -f $HISTORY ]]; then
        echo "history (last 10):"
        tail -n 10 "$HISTORY" | sed 's/^/    /'
    fi
    if [[ -n $live ]]; then
        local prev
        prev=$(previous_id "$live")
        echo "rollback target: ${prev:-none}"
    fi
}

main() {
    local cmd=${1:-}
    shift || true
    case $cmd in
        release) cmd_release "$@" ;;
        rollback) cmd_rollback "$@" ;;
        list) cmd_list ;;
        health) health_check ;;
        *) usage ;;
    esac
}

main "$@"
