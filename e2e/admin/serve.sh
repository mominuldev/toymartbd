#!/bin/sh
# Boots the real Laravel app for the admin E2E suite on a throwaway SQLite database.
# APP_ENV=local makes DatabaseSeeder create owner@toymart.test and the demo brands.
set -e
cd "$(dirname "$0")/../.."
PHP_BIN="${PHP_BIN:-php}"
DB_FILE="$(pwd)/database/e2e.sqlite"
rm -f "$DB_FILE" && touch "$DB_FILE"

export APP_ENV=local APP_DEBUG=false APP_URL=http://localhost:8124
export DB_CONNECTION=sqlite DB_DATABASE="$DB_FILE"
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync
export SANCTUM_STATEFUL_DOMAINS=localhost:8124 SESSION_DOMAIN=
# No storefront in this run: the revalidation job must skip, not call out.
export STOREFRONT_URL= STOREFRONT_REVALIDATE_SECRET=
export SCOUT_DRIVER=null SENTRY_LARAVEL_DSN=
# The demo catalogue without its Figma photos: 16 × 9 image encodes would slow every run.
export DEMO_SEED_IMAGES=false
# Specs count orders and queue badges exactly: no demo order history in this run.
export DEMO_SEED_TRANSACTIONS=false
# Every spec signs in; the production limit (5/min) would throttle the sixth.
export AUTH_LOGIN_PER_MINUTE=60
# …and the per-account limit (10 / 15 min): every spec signs the same owner in.
export AUTH_LOGIN_PER_ACCOUNT_PER_15_MIN=200

"$PHP_BIN" artisan migrate:fresh --seed --force >/dev/null
# The file cache outlives migrate:fresh: drop last run's sign-in counters.
"$PHP_BIN" artisan cache:clear >/dev/null
exec "$PHP_BIN" artisan serve --host=localhost --port=8124 --no-reload
