#!/bin/sh
# Boots the real Laravel app for the admin accessibility audit on a throwaway SQLite database with
# the full demo data set (orders, customers, invoices, ledger), so every screen has content to audit.
# A11Y_ADMIN_DB overrides the database file (default: a temp file, outside the repo).
set -e
cd "$(dirname "$0")/../.."
PHP_BIN="${PHP_BIN:-php}"
PORT="${A11Y_ADMIN_PORT:-8344}"
DB_FILE="${A11Y_ADMIN_DB:-${TMPDIR:-/tmp}/toymart-admin-a11y.sqlite}"
rm -f "$DB_FILE" && touch "$DB_FILE"

export APP_ENV=local APP_DEBUG=false APP_URL="http://localhost:$PORT"
export DB_CONNECTION=sqlite DB_DATABASE="$DB_FILE"
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync
export SANCTUM_STATEFUL_DOMAINS="localhost:$PORT" SESSION_DOMAIN=
export STOREFRONT_URL= STOREFRONT_REVALIDATE_SECRET=
export SCOUT_DRIVER=null SENTRY_LARAVEL_DSN=
export DEMO_SEED_IMAGES=false AUTH_LOGIN_PER_MINUTE=60

"$PHP_BIN" artisan migrate:fresh --seed --force >/dev/null
"$PHP_BIN" artisan db:seed --class=DemoDataSeeder --force >/dev/null
exec "$PHP_BIN" artisan serve --host=localhost --port="$PORT" --no-reload
