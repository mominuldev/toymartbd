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
# Queued jobs run inline here, so an order mail would open SMTP inside the request: CI's .env
# (from .env.example) points at 127.0.0.1:1025 with nothing listening, and the sale returned 500.
export MAIL_MAILER=log
export SANCTUM_STATEFUL_DOMAINS="localhost:$PORT" SESSION_DOMAIN=
export STOREFRONT_URL= STOREFRONT_REVALIDATE_SECRET=
export SCOUT_DRIVER=null SENTRY_LARAVEL_DSN=
export DEMO_SEED_IMAGES=false AUTH_LOGIN_PER_MINUTE=60
# Every audit signs the owner in again; the per-account limit (10 / 15 min) would throttle it.
export AUTH_LOGIN_PER_ACCOUNT_PER_15_MIN=200
# …and the admin API limit (300/min per user): the suite is one owner, and a fast runner
# crossed it (429 on /auth/me broke a page). Production keeps 300.
export ADMIN_API_PER_MINUTE=3000
# Staff two-factor is not enforced here, so the owner reaches every screen without a code; the
# Phase 10 two-factor audit enrols its own accounts and signs in with a real code.
export TWO_FACTOR_ENFORCE=false

"$PHP_BIN" artisan migrate:fresh --seed --force >/dev/null
"$PHP_BIN" artisan db:seed --class=DemoDataSeeder --force >/dev/null
# The demo catalog has no barcodes. Give the 3x3 cube an in-store EAN-13 (prefix 20, restricted
# circulation: a shop's own label) so the POS keyboard-only test can scan it like a wedge scanner.
"$PHP_BIN" artisan tinker --execute="App\\Domain\\Catalog\\Models\\ProductVariant::query()->where('sku', 'TMB-RUB-3X3')->update(['barcode' => '2000000000015']);" >/dev/null
# The file cache outlives migrate:fresh: drop the last run's sign-in counters.
"$PHP_BIN" artisan cache:clear >/dev/null
exec "$PHP_BIN" artisan serve --host=localhost --port="$PORT" --no-reload
