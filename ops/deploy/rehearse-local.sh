#!/usr/bin/env bash
# Local rehearsal of deploy.sh against a fake server layout (docs/ops/DEPLOYMENT.md §9).
#
#   ops/deploy/rehearse-local.sh <work-dir> [port]
#
# Builds artifacts from this checkout (needs public/build from `npm run build`), then drives
# deploy.sh through: first release → a release with a destructive migration (maintenance
# window) → a release whose health check fails (automatic rollback) → a release whose migration
# fails (old release keeps serving) → one-command rollback → pruning. PHP's built-in server
# stands in for nginx + PHP-FPM; "reload PHP" restarts it on the new `current`.
#
# Everything lives under <work-dir>: its own SQLite database, file cache, sync queue. It never
# touches the repo's .env, database or storage. Horizon's terminate signal goes to a private
# Redis prefix. composer install uses the local Composer cache (it may download a package the cache lacks).

# pass() never fails, so `cond && pass || fail` is a safe if/else here; the PHP snippet is
# single-quoted on purpose.
# shellcheck disable=SC2015,SC2016

set -Eeuo pipefail

work=${1:?usage: $0 <work-dir> [port]}
port=${2:-8351}
repo=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
deploy=$repo/ops/deploy/deploy.sh
build=$repo/ops/deploy/build-artifact.sh

mkdir -p "$work"
work=$(cd "$work" && pwd)
root=$work/server
art=$work/artifacts
src=$work/src
pidfile=$work/php-server.pid
passes=0
fails=0

pass() { passes=$((passes + 1)); printf '\n  PASS  %s\n\n' "$*"; }
fail() { fails=$((fails + 1)); printf '\n  FAIL  %s\n\n' "$*"; }
step() { printf '\n==== %s ====\n' "$*"; }

stop_server() {
    if [[ -f $pidfile ]]; then
        kill "$(cat "$pidfile")" 2>/dev/null || true
        rm -f "$pidfile"
    fi
}
trap stop_server EXIT

# The fake "reload PHP-FPM": restart php -S from the (new) current/public.
cat > "$work/reload-php.sh" <<EOF
#!/usr/bin/env bash
set -euo pipefail
if [[ -f "$pidfile" ]]; then kill "\$(cat "$pidfile")" 2>/dev/null || true; sleep 0.5; fi
cd "$root/current/public"
nohup php -S 127.0.0.1:$port "$root/current/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" \
    >> "$work/php-server.log" 2>&1 &
echo \$! > "$pidfile"
sleep 1
EOF
chmod +x "$work/reload-php.sh"

stop_server
rm -rf "$root" "$art" "$src"
mkdir -p "$root/shared/storage" "$art"
: > "$work/db.sqlite"

key=$(cd "$repo" && php artisan key:generate --show --no-ansi)
cat > "$root/shared/.env" <<EOF
APP_NAME="Toy Mart BD"
APP_ENV=production
APP_KEY=$key
APP_DEBUG=false
APP_URL=http://127.0.0.1:$port
LOG_CHANNEL=single
LOG_LEVEL=warning
DB_CONNECTION=sqlite
DB_DATABASE=$work/db.sqlite
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
SCOUT_DRIVER=null
MAIL_MAILER=log
REDIS_CLIENT=predis
REDIS_PREFIX=p10a_rehearsal_
HORIZON_PREFIX=p10a_rehearsal_horizon:
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:$port
EOF

export APP_ROOT=$root
export RELOAD_CMD="$work/reload-php.sh"
export HEALTH_RETRIES=3 HEALTH_INTERVAL=1
export KEEP_RELEASES=2
export MIN_FREE_MB=300
export COMPOSER_FLAGS="--no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --prefer-dist --quiet"


current() { basename "$(readlink "$root/current")"; }
health_code() { curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port/api/v1/health" || true; }
has_table() { php -r '$p=new PDO("sqlite:".$argv[1]); exit($p->query("select count(*) from sqlite_master where type=\"table\" and name=".$p->quote($argv[2]))->fetchColumn() ? 0 : 1);' "$work/db.sqlite" "$1"; }

A=aaaaaaa1111111111111111111111111111111a1
B=bbbbbbb2222222222222222222222222222222b2
C=ccccccc3333333333333333333333333333333c3
D=ddddddd4444444444444444444444444444444d4

step "build artifact A from the checkout"
"$build" "$A" "$art/$A.tar.gz" "$repo"
cp "$art/$A.tar.gz" "$art/base.tar.gz" # a successful release deletes its artifact

# Variants are built from A's own contents, so they differ from A only in what the test changes.
variant() { # <id> <patch-function>
    rm -rf "$src"
    mkdir -p "$src"
    tar -xzf "$art/base.tar.gz" -C "$src"
    rm -f "$src/REVISION"
    "$2"
    "$build" "$1" "$art/$1.tar.gz" "$src"
}

patch_b() { # a new table, marked destructive → maintenance window
    cat > "$src/database/migrations/2099_01_01_000001_rehearsal_destructive.php" <<'PHP'
<?php
// @deploy:destructive — rehearsal only: proves the maintenance window opens and closes.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('p10_rehearsal_b', function (Blueprint $t): void { $t->id(); }); }
};
PHP
}
patch_c() { # health endpoint answers 503 → automatic rollback
    patch_b
    perl -0pi -e 's/->setStatusCode\(\$report->critical\(\) \? 503 : 200\)/->setStatusCode(503)/' \
        "$src/app/Http/Controllers/Api/V1/HealthController.php"
    grep -q 'setStatusCode(503)' "$src/app/Http/Controllers/Api/V1/HealthController.php"
}
patch_d() { # a migration that throws → swap never happens
    patch_b
    cat > "$src/database/migrations/2099_01_01_000002_rehearsal_broken.php" <<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void { throw new RuntimeException('rehearsal: broken migration'); }
};
PHP
}

step "0. a shared .env with an unfilled CHANGE_ME is refused"
echo "MAIL_HOST=CHANGE_ME" >> "$root/shared/.env"
if "$deploy" release "$art/$A.tar.gz" "$A" > "$work/release-0.log" 2>&1; then
    fail "deploy ran with CHANGE_ME in .env"
else
    grep -q 'MAIL_HOST' "$work/release-0.log" && [[ ! -e $root/current ]] && pass "refused, naming MAIL_HOST; nothing went live" || fail "wrong refusal"
fi
perl -ni -e 'print unless /^MAIL_HOST=CHANGE_ME$/' "$root/shared/.env"

step "1. first release (A)"
if "$deploy" release "$art/$A.tar.gz" "$A" && [[ $(current) == "$A" && $(health_code) == 200 ]]; then
    pass "A is live and /api/v1/health is 200"
else
    fail "first release"
fi
[[ -L $root/releases/$A/storage && -L $root/releases/$A/.env && -f $root/releases/$A/bootstrap/cache/config.php ]] \
    && pass "A links shared storage + .env and has a cached config" || fail "A layout"
grep -q "'release' => '$A'" "$root/releases/$A/bootstrap/cache/config.php" \
    && pass "cached config carries SENTRY_RELEASE = sha" || fail "sentry release not baked into config"

step "2. release B with a migration marked @deploy:destructive"
variant "$B" patch_b
if "$deploy" release "$art/$B.tar.gz" "$B" 2>&1 | tee "$work/release-b.log" && [[ $(current) == "$B" ]]; then
    pass "B is live"
else
    fail "release B"
fi
grep -q 'destructive migration(s) pending' "$work/release-b.log" && pass "maintenance window opened for the destructive migration" || fail "no maintenance window"
[[ ! -f $root/shared/storage/framework/down && $(health_code) == 200 ]] && pass "app is up again after the window" || fail "app left down"
has_table p10_rehearsal_b && pass "B's migration applied" || fail "B's table missing"

step "3. release C whose health check fails → automatic rollback"
variant "$C" patch_c
if "$deploy" release "$art/$C.tar.gz" "$C" > "$work/release-c.log" 2>&1; then
    fail "release C should have exited non-zero"
else
    pass "release C exited non-zero"
fi
cat "$work/release-c.log"
[[ $(current) == "$B" && $(health_code) == 200 ]] && pass "rolled back: B is live and healthy" || fail "C did not roll back (current=$(current))"
grep -q "failed $C" "$root/releases.log" && pass "C recorded as failed" || fail "C not recorded"

step "4. release D whose migration throws → swap never happens"
variant "$D" patch_d
if "$deploy" release "$art/$D.tar.gz" "$D" > "$work/release-d.log" 2>&1; then
    fail "release D should have exited non-zero"
else
    pass "release D exited non-zero"
fi
tail -n 8 "$work/release-d.log"
[[ $(current) == "$B" && $(health_code) == 200 ]] && pass "B kept serving through D's failed migration" || fail "D broke the live release"

step "5. one-command rollback (B → A); schema stays"
if "$deploy" rollback && [[ $(current) == "$A" && $(health_code) == 200 ]]; then
    pass "rolled back to A, healthy"
else
    fail "rollback to A"
fi
has_table p10_rehearsal_b && pass "schema NOT rolled back (B's table remains, A ignores it)" || fail "schema changed on rollback"

step "6. redeploy B (it is not live), then list"
# A successful release deletes its artifact, so B is rebuilt — as CI would for a re-run.
variant "$B" patch_b
"$deploy" release "$art/$B.tar.gz" "$B"
[[ $(current) == "$B" ]] && pass "B live again" || fail "redeploy B"
"$deploy" list

step "7. pruning keeps at most KEEP_RELEASES=$KEEP_RELEASES"
count=$(find "$root/releases" -mindepth 1 -maxdepth 1 -type d ! -name '.*' | wc -l | tr -d ' ')
((count <= KEEP_RELEASES)) && pass "$count release(s) on disk" || fail "$count releases on disk"

printf '\nrehearsal: %d passed, %d failed\n' "$passes" "$fails"
((fails == 0))
