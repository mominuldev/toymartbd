# Staging — runbook

**Purpose:** the environment for Gate 4 (owner UAT, `DEVELOPMENT-ROADMAP.md` §Phase 4) and the Phase 5 ten-order profit check (`PROFIT-RECONCILIATION.md`). **Architecture:** `SYSTEM-ARCHITECTURE.md` §7 (queues, schedule), §11 (health, logs), §12 (environments). **Smoke check:** `scripts/staging/smoke.sh`.

> **Status, 2026-11-13.** No staging host exists yet. Everything below has been rehearsed on a local machine (SQLite + local Redis + local Meilisearch, `artisan serve`, Horizon). The smoke script passed there: 0 failures, 1 warning (no `SITE_URL` was set). The owner decides the hosting choices in §1. They are marked **OWNER**.

## 1. Decisions that belong to the owner

| Decision | Options | Notes |
|---|---|---|
| **OWNER** API host | (a) one small VPS running Laravel, MySQL, Redis and Meilisearch; (b) a VPS for the app plus a managed MySQL | §12 assumes a single VPS for production. Staging can be smaller, but should run the **same versions**. |
| **OWNER** Storefront host | (a) the same VPS, running `next start` behind the web server; (b) Vercel (the §12 plan) | Vercel gives preview URLs per PR. The same VPS keeps everything in one bill. |
| **OWNER** Domains | e.g. `staging-api.<domain>` and `staging.<domain>` | Both need HTTPS. The admin is served by the API host at `/admin`. |
| **OWNER** Data | reference data only (default), or reference data plus the demo catalogue | §12 says "anonymised production clone"; there is no production yet. The Gate 4 UAT needs **real** products that the owner enters (see `UAT-GATE-4.md`), so the default is **no demo catalogue**. |
| **OWNER** Backup bucket | any S3-compatible store (S3, R2, B2, Wasabi) | Required for the still-open P3-CI-03 restore rehearsal (`backup-and-restore.md`). |
| **OWNER** SMS / WhatsApp provider | SMS: REVE SMS via `https://smpp.ajuratech.com:7790` (owner, 2026-11-13). WhatsApp: none yet | SMS is real: the owner enters the REVE credentials at **Admin → Integrations → Messaging** (`/admin/integrations/messaging`); env is only a fallback. WhatsApp stays off so order updates go by SMS (§3.4). |
| **OWNER** Payment gateway | SSLCommerz sandbox credentials, or none | Gateway stays **inactive** for Gate 4. COD only (§3.5). |

We do not recommend a specific provider; `docs/_research/` has no hosting comparison. Pick on price and on Dhaka latency.

## 2. Server requirements

| Component | Version | Why |
|---|---|---|
| PHP | 8.4+ (CI uses 8.4; the lead runs 8.5) with `bcmath`, `intl`, `pdo_mysql`, `redis` or predis, `gd` or `imagick`, `zip`, `openssl`, `pcntl` (Horizon) | `composer.json` requires `ext-bcmath` and `ext-intl` |
| MySQL | **8.4**, `utf8mb4_0900_ai_ci` | Rule 34. Bangla text. |
| Redis | 7.x | Cache, sessions, queues (Horizon) |
| Meilisearch | 1.x, matching `meilisearch/meilisearch-php` ^1.17 | Product search |
| Node | 24 LTS, for the build and the storefront | Admin bundle via Vite, Next.js 16 storefront |
| CLI tools | `mysqldump`, `mysql`, `tar`, `openssl` | `toymart:backup` and the restore rehearsal |
| Process supervisor | systemd or supervisord | Horizon, `next start`, the scheduler cron |
| Web server | nginx or Caddy, HTTPS, PHP-FPM | |

## 3. Environment variables

Start from `.env.example`. Every variable below is read by `config/*.php`. The list covers those with **no safe default for staging**: the default is empty, local-only, or wrong for a shared environment. Everything not listed keeps its `.env.example` value.

### 3.1 Core

| Variable | Staging value | Why it must be set |
|---|---|---|
| `APP_ENV` | `staging` | Horizon picks its supervisors by this name (`config/horizon.php`). The seeder only creates demo users and catalogue in `local` / `testing`. |
| `APP_KEY` | `php artisan key:generate --show`, **escrowed** | Encrypted columns (gateway and courier credentials) and sessions. Never in a backup. |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://staging-api.<domain>` | Links, media URLs, smoke script |
| `TRUSTED_PROXIES` | the load balancer / reverse proxy IP or CIDR, plus the storefront server's egress IP | Default is none, and `*` is refused. Without it, rate limits and audit IPs see the proxy instead of the customer. |
| `LOG_STACK` | `json` | One JSON line per record on stdout (`observability.md`) |
| `LOG_LEVEL` | `info` | |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `mysql` + the staging database | `.env.example` has `root` / `secret` |
| `DB_COLLATION` | `utf8mb4_0900_ai_ci` | |
| `REDIS_HOST` / `REDIS_PASSWORD` / `REDIS_PORT` | the staging Redis | |
| `REDIS_PREFIX`, `CACHE_PREFIX` | e.g. `tmbd_staging_` | **Required if Redis is shared** with another environment. A shared prefix lets staging's Horizon run production jobs. |
| `QUEUE_CONNECTION` / `CACHE_STORE` / `SESSION_DRIVER` | `redis` / `redis` / `redis` | Code defaults are `database`. Horizon needs Redis. |
| `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE` | the API host, `true` | Admin cookie session over HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | the API host (it serves `/admin`) | Without it the admin's cookie login fails |
| `SANCTUM_TOKEN_PREFIX` | `tmbd_` | Secret scanners can spot a leaked token |
| `MAIL_MAILER` + `MAIL_*` | `log`, or an SMTP sandbox (Mailpit / Mailtrap) | Stock-drift and low-stock mails go here. The code default is `log`. |

### 3.2 Storefront link

| Variable | Value |
|---|---|
| `STOREFRONT_URL` | `https://staging.<domain>` (also the SSLCommerz return base) |
| `STOREFRONT_REVALIDATE_SECRET` | `openssl rand -hex 32`. The **same** value goes in the storefront's env. |

**In the storefront's own env** (`toymartbd-app/storefront/.env.example`; all server-only, never `NEXT_PUBLIC_`):

| Variable | Value |
|---|---|
| `API_URL` | `https://staging-api.<domain>` |
| `STOREFRONT_API_TOKEN` | output of `php artisan toymart:storefront-token` (printed once; `--rotate` revokes the old ones) |
| `STOREFRONT_REVALIDATE_SECRET` | same as Laravel's |
| `SITE_URL` | `https://staging.<domain>` |

### 3.3 Search, media, observability

| Variable | Value |
|---|---|
| `SCOUT_DRIVER` | `meilisearch`. The code default is `null`; the smoke check fails on `skipped`. |
| `MEILISEARCH_HOST` / `MEILISEARCH_KEY` | the staging instance and its master key. Not the `.env.example` placeholder. |
| `SCOUT_PREFIX` | e.g. `staging_` if Meilisearch is shared |
| `MEDIA_DISK` / `MEDIA_ORIGINALS_DISK` / `MEDIA_CDN_URL` | `public` / `local` / empty until the owner picks a CDN |
| `SENTRY_LARAVEL_DSN`, `SENTRY_ENVIRONMENT=staging`, `SENTRY_RELEASE=<git sha>` | Optional. Empty disables Sentry. |

### 3.4 Notifications — left on the log drivers

| Variable | Value | Effect |
|---|---|---|
| `SMS_DRIVER`, `SMS_REVE_*`, `SMS_COST_PER_SEGMENT` | leave unset (optional fallback) | **SMS credentials are entered in the admin, not here** (owner decision 2026-11-13). Sign in as super_admin or a manager (managers hold Integrations since the owner's decision of 2026-11-13) → **Admin → Integrations → Messaging** (`/admin/integrations/messaging`): Provider = REVE SMS, API base URL (`https://smpp.ajuratech.com:7790`), API key and secret key from the provider panel, the sender ID — the owner's REVE sender is **non-masking**, so it is the number, digits only (`880XXXXXXXXXX`, `01XXXXXXXXX` or a 5–15-digit short code; a brand name is refused) — and the cost per segment, **৳0.26** (the owner's REVE rate, 2026-11-13; also the default). Keys are stored encrypted in `settings` (needs a stable `APP_KEY` — rotating it makes saved keys unreadable, and they must be re-entered), are never shown again (only the last 4 characters), and every change is written to `audit_sensitive` as `credential_changed` without the value. A saved value applies to the next SMS, queue workers included — no restart. These env variables are only used for a value nobody has saved in the admin (CI, older deployments); never commit real keys. Before the UAT, use **Send test SMS** on that screen (or `php artisan toymart:sms:test 017XXXXXXXX`, and again with `--bn` — same settings) and confirm both English and Bangla arrive intact. |
| `WHATSAPP_DRIVER` | `log` | No WhatsApp Business account yet. `WHATSAPP_ACCESS_TOKEN` stays empty. |
| `WHATSAPP_ENABLED` | `false` (the default) | **Must stay off while there is no WhatsApp account.** It is the fallback for the **WhatsApp** switch on Admin → Integrations → Messaging, which wins once saved — check the switch is off there too. With it on, order updates go to the WhatsApp log driver, which reports success, so the SMS fallback never fires and no customer gets an order SMS. |
| `SMS_LOG_CHANNEL` | empty | The log line goes to the default channel |

With the REVE provider selected each SMS is really sent; the server log gets a `sms.reve.accepted` / `sms.reve.rejected` line with the masked phone and the provider's status, never the message text or the keys. What a log driver writes: a `sms.log_driver` / `whatsapp.log_driver` line with the **masked** phone (`017****5678`) and the template name. **It does not log the message text.** One `notification_logs` row per message, status **Sent**, appears in **Admin → Automations → Message log** (`/admin/automations/log`). `UAT-GATE-4.md` §0 explains what the owner will and will not see.

### 3.5 Payments — gateway inactive, COD only

| Variable | Value |
|---|---|
| `SSLCOMMERZ_STORE_ID` / `SSLCOMMERZ_STORE_PASSWORD` | Sandbox credentials if the owner has them, else empty |
| `SSLCOMMERZ_SANDBOX` | `true` |
| `SSLCOMMERZ_IPN_ALLOWED_IPS` | SSLCommerz's published IPN IPs, if sandbox testing is wanted. We have not verified that list. |
| `PAYMENT_ONLINE_EXPIRY_MINUTES` / `PAYMENT_ONLINE_MAX_ATTEMPTS` | `.env.example` placeholders (owner decision) |

Keep the gateway **inactive**, and check it after seeding:

```bash
php artisan toymart:payments:gateway sslcommerz      # shows active / verified / sandbox
```

Never pass `--verify` on staging. Rule 33a: a provider is verified only after a live merchant account has been tested end to end.

> **Known gap — online payments in the ledger.** Not blocking a COD-only Gate 4. An SSLCommerz payment stores the fee the gateway kept (`payments.fee_amount`), but **nothing writes it to the ledger as `gateway_fee`**. An online order's net profit is therefore overstated by the fee. `toymart:finance:order-breakdown` flags such an order as `DIFFERS` on `gateway_fees` (see `PROFIT-RECONCILIATION.md`). Online cash receipts are not booked either: there is no `payment`-source ledger writer yet.

### 3.6 Couriers

`PATHAO_*` stays empty and `STEADFAST_VERIFIED=false` (rule 33a). Fulfilment is manual until Phase 6: **Mark shipped / Mark delivered** in the admin.

### 3.7 Backups

`BACKUP_DISK=backups_s3`, `BACKUP_ARCHIVE_PASSWORD` (≥ 16 characters, escrowed), and `BACKUP_S3_*` (**OWNER** bucket). See `backup-and-restore.md`.

### 3.8 Business placeholders (owner values, editable later)

`RISK_FIRST_ORDER_HOLD_THRESHOLD` (default ৳3,000), `REFUND_APPROVAL_THRESHOLD` (৳2,000), `RETURN_WINDOW_DAYS` (7), `INVENTORY_ADJUSTMENT_APPROVAL_UNITS` / `_VALUE`, `STORE_HOTLINE` (placeholder `09600123456`), `STORE_PUBLIC_DOMAIN`. Delivery charges and the free-delivery threshold are `delivery.*` settings in the database (DeliverySeeder placeholders: ৳70 / ৳100 / ৳130, free from ৳3,000), not env.

## 4. First deploy

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build                     # admin bundle → public/build
php artisan migrate --force
php artisan db:seed --force                 # see below
php artisan storage:link
php artisan toymart:search:configure && php artisan toymart:search:reindex
php artisan toymart:notifications:sync-templates
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan toymart:storefront-token        # copy into the storefront env, once
```

**Seeding.** With `APP_ENV=staging`, `DatabaseSeeder` runs only the **reference** seeders:

- roles and permissions
- the Bangladesh geography, in Bangla and English
- catalogue reference data
- media folders
- delivery providers and settings
- expense categories

It creates **no login and no demo catalogue**; those run only in `local` / `testing`.

Create the owner's account by hand:

```bash
php artisan tinker --execute '
  $u = App\Models\User::create(["name" => "Owner", "email" => "OWNER_EMAIL", "password" => bcrypt("A-LONG-PASSWORD")]);
  $u->assignRole(App\Models\Enums\Role::SuperAdmin->value);'
```

**Demo catalogue only if the owner asks:** `php artisan db:seed --class=CategoryDemoSeeder --force`, then `BrandDemoSeeder`, `DemoCatalogSeeder` and `DemoInventorySeeder`. Do not do this before the Gate 4 run. Demo products mixed with real ones make the ten-order profit check harder to read.

**Every later deploy:**

```bash
php artisan migrate --force && php artisan config:cache && php artisan route:cache
php artisan horizon:terminate     # the supervisor restarts it on the new code
scripts/staging/smoke.sh
```

## 5. Queues — Horizon

Horizon supervises every queue (`config/horizon.php`, one supervisor per queue, process counts from §7):

| Queue | Processes | Carries |
|---|---|---|
| `critical` | 3 | order side effects |
| `courier` | 2 | Phase 6 bookings |
| `notify` | 2 | **customer order messages**, inventory alert mail |
| `tracking` | 2 | GA4 / Meta server events |
| `index` | 1 | Meilisearch sync, media renditions |
| `reports` | 1 | ledger write-offs, exports |
| `default` | 1 | anything that names no queue |

Run Horizon under systemd / supervisord, with `php artisan horizon` restarting on exit. Check it with `php artisan horizon:status` and `php artisan horizon:supervisors`.

> **Fixed during this rehearsal.** Until `config/horizon.php` existed, Horizon used the package default: one supervisor on `default` only, and **no `staging` environment at all**. On staging, nothing would have run. Everywhere, order notifications, media renditions, search sync and damaged-stock write-offs would have waited in Redis for ever. `tests/Feature/Foundation/HorizonQueuesTest.php` now fails if a queue named in `app/` or `config/` has no supervisor.

Alternative without Horizon (not recommended): `php artisan queue:work redis --queue=critical,courier,notify,tracking,index,reports,default`.

## 6. Scheduler

Use one cron entry, as the deploy user, on **one** server: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`. Every task is `onOneServer()` and `withoutOverlapping()`. The schedule (`routes/console.php`, times in Asia/Dhaka):

| Command | When (Dhaka) | What |
|---|---|---|
| `toymart:payments:expire` | every 5 minutes | Unpaid online orders past their window: re-query, then expire (stock released, order cancelled) |
| `toymart:couriers:poll` | every 15 minutes | Courier status reconciliation: re-query every in-flight shipment with no update for `couriers.status_poll.stale_minutes` (default 60) on the `courier` queue; resolves bulk-`submitted` parcels by merchant ref where the driver can. Terminal shipments are never polled. |
| `toymart:finance:recurring-expenses` | 1st of each month, 00:30 | Generate recurring expenses |
| `toymart:finance:rollup` | daily 01:00 | `daily_financial_summaries` for yesterday and today |
| `toymart:finance:verify` | daily 01:30 | Invariant: order financials = ledger. Exits non-zero on drift; never corrects. |
| `toymart:backup` | daily 02:00 | Encrypted backup to `BACKUP_DISK` |
| `toymart:inventory:reconcile` | daily 03:30 | Invariant: stock movements = stock levels. Alerts and mails on drift; never corrects. |
| `toymart:inventory:digest` | daily 09:00 (`inventory.alerts.digest_time`) | Low-stock digest to managers |

These §7 tasks are **not scheduled yet**:

- abandoned-cart detection
- sitemap regeneration
- stale-cart cleanup
- the nightly search reindex
- `toymart:search:lag-check`

The smoke script runs the lag check on demand. `php artisan schedule:list` shows the live schedule.

## 7. Backups

`php artisan toymart:backup` runs nightly at 02:00. Before UAT, run it once by hand, then `php artisan toymart:backup:restore-rehearsal`. That rehearsal on staging is the open P3-CI-03 acceptance criterion. See `backup-and-restore.md`.

## 8. Smoke checks

```bash
APP_URL=https://staging-api.<domain> SITE_URL=https://staging.<domain> \
STOREFRONT_API_TOKEN=tmbd_… scripts/staging/smoke.sh
```

`PHP_BIN` overrides the PHP binary, and `SMOKE_SKIP_HORIZON=1` skips the worker checks. The script exits 0 when nothing failed. It checks:

1. `GET /up`
2. `GET /api/v1/health`: database, cache/Redis, queue, Meilisearch are all `ok`
3. Horizon is running, with a running supervisor for each of the seven queues
4. Every scheduled `toymart:*` task is registered, and yesterday's finance rollup row exists. This is a WARN on day one; on later days it means the cron is not firing.
5. Search index lag
6. The storefront token on an **authenticated** endpoint (`/api/v1/storefront/districts`). `/ping` is public, so it proves nothing.
7. Storefront `/` and `/bn/` return 200
8. `toymart:inventory:reconcile`
9. `toymart:finance:verify`

Side effects: the reconcile records its run and, on drift, raises an inventory alert and mails it. Nothing is ever corrected. The script never prints the token, a phone number or an address.

Local rehearsal (2026-11-13, SQLite + Redis + Meilisearch, Horizon, port 8314):

- **All checks passed.** One WARN: `SITE_URL` was unset.
- **A bogus token was caught:** `FAIL storefront token refused (… → 401)`.
- **An induced stock drift was caught:** `FAIL invariant: stock drift`, exit 1.
- **The pre-fix Horizon failed the check:** it ran only `supervisor-1` on `default`, and a `notify` job sat unprocessed until `config/horizon.php` was added.
