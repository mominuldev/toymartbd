# Observability — request ids, logs, Sentry, health, alerts

**Tasks:** P3-CI-04 (API half), P10 monitoring (health, Sentry for all three apps, business alerts, log shipping, synthetic check) · **Architecture:** `SYSTEM-ARCHITECTURE.md` §11 (Errors, Logs) · **Rule:** CLAUDE.md 20 — never log a credential, a full phone number or a full address.

## Request id

`App\Http\Middleware\AssignRequestId`, prepended to the global middleware stack (`bootstrap/app.php`).

- An incoming `X-Request-Id` is kept only if it matches `^[A-Za-z0-9._-]{8,128}$`; otherwise a UUIDv7 is generated. A client cannot inject newlines or markup into log lines through it.
- It is returned as `X-Request-Id` on every response and as `request_id` in every RFC 9457 problem body, so a customer or support can quote it.
- It is stored in Laravel's `Context` under `request_id`. Context is (a) appended to every log record as `extra.request_id` and (b) serialised into every job dispatched during the request and restored on the worker — so a queued job's log lines and Sentry events carry the request's id (tested in `tests/Feature/Observability/RequestIdTest.php` with a real `database` queue worker).
- **Storefront follow-up:** the Next.js Route Handlers / Server Components must forward the edge's `X-Request-Id` on their API calls (or generate one) for the trace to span storefront → API → worker.

## Logs

- Production and staging: `LOG_STACK=json` — Monolog `JsonFormatter`, one object per line on stdout (`LOG_JSON_STREAM` to override), with stack traces, for the host's log shipper.
- Every channel in `config/logging.php` (including Sentry's `sentry` / `sentry_logs`) carries the `App\Support\Logging\RedactLogs` tap, asserted by a test. It adds, on each handler:
  - the `RedactSensitiveData` processor (message, context, extra — after Laravel's Context processor, so shared context is scrubbed too);
  - a `RedactingFormatter` around the handler's formatter, which scrubs the rendered text. This catches what a processor cannot rewrite: a Throwable's message — e.g. a `QueryException` whose SQL bindings contain a customer's phone.

## Redaction rules (`App\Support\Logging\RedactSensitiveData`)

| What | Rule | Result |
|---|---|---|
| Bangladeshi mobile anywhere in a string | `(?<!\d)(?:\+?880\|0)1[3-9]\d{8}(?!\d)` | `017****5678` (same as `PhoneNumber::masked()`) |
| Value under a sensitive key, any depth, case-insensitive, `-` ≡ `_` | key equals `password`, `password_confirmation`, `current_password`, `new_password`, `token`, `secret`, `api_key`, `apikey`, `authorization`, `proxy_authorization`, `cookie`, `set_cookie`, `credentials`, `address`, `address_line(_1/_2)`, `php_auth_pw` — or contains `password`, `token`, `secret`, `api_key`, `credential`, `address_line`, `_address`, `authorization`, `cookie` | `[redacted]` |
| Secrets inside free text | `"<sensitive>": "…"` in JSON (also JSON-in-a-JSON-string), `<sensitive>=…` in query/form strings, `Bearer …` / `Basic …` | value → `[redacted]` |

Over-redaction is deliberate (`ip_address`, `tokenable_type` are caught): a lost field in a log costs less than a leaked one. Customer names are not redacted.

## Sentry

`sentry/sentry-laravel` 4.29.0 (with `sentry/sentry` 4.34.0). Config: `config/sentry.php`.

- **Disabled unless `SENTRY_LARAVEL_DSN` is set** (empty in `.env.example`).
- `send_default_pii` is hard-coded `false` (not env-driven): no deployment can opt in.
- `before_send` = `SentryEventScrubber::beforeSend`: tags the event `request_id` from the Context (web and worker), and applies the redaction above to the message, exception values, stack-frame vars and source context, request data, extra, contexts, tags and breadcrumbs; drops user email / IP / username (the id stays). `before_breadcrumb` scrubs breadcrumbs as they are recorded.
- Exceptions are reported from the existing handler via `Integration::handles($exceptions)` in `bootstrap/app.php`. The RFC 9457 renderer is unchanged.
- SQL bindings stay off in breadcrumbs and spans (SDK default). Set `SENTRY_RELEASE` to the git SHA in the deploy.
- Tested with a capturing transport (`tests/Feature/Observability/SentryReportingTest.php`): a thrown error produces one event tagged with the request id, and the serialised envelope contains no phone, password, address or bearer token.

## Follow-ups (P3-CI-04 list — status after P10)

- ~~Sentry for the Next.js storefront and the React admin~~ — done in P10, see below.
- Document `request_id` in the `ProblemDetails` OpenAPI schema (`app/Providers/ApiServiceProvider.php`), then `composer openapi:export`.
- ~~`last_backup_at` on the health endpoint~~ — done in P10 (`checks.backup`).
- ~~Uptime-monitor heartbeat for the nightly backup~~ — `backup_stale` alert + `checks.backup` (P10).

---

# P10 monitoring

Everything below is provider-agnostic and **off until configured**: no DSN = no Sentry, no
token = no health detail / no synthetic check, `MONITORING_ALERTS_ENABLED` defaults to true only
when `APP_ENV=production`. Thresholds are placeholders chosen before launch traffic exists.

## Health endpoint — `GET /api/v1/health`

| Status | HTTP | Meaning |
|---|---|---|
| `ok` | 200 | Nothing failed. |
| `degraded` | 200 | A non-critical check failed. Checkout still works; a human must look. |
| `down` | 503 | Database, cache or Redis failed. The load balancer should take the node out. |

- **Public body:** `{"status": "...", "checked_at": "..."}` only — no hosts, versions, depths or errors.
- **Detail:** send `X-Health-Token: <MONITORING_HEALTH_TOKEN>` (compared with `hash_equals`; empty token = detail disabled). Adds `checks`:

| Check | Fails when | Effect |
|---|---|---|
| `database` | `select 1` throws | down |
| `cache` | write/read-back fails (Redis in production) | down |
| `redis` | `PING` fails — only when queue, cache or session use Redis, else `skipped` | down |
| `queue` | backend unreachable, or any queue deeper than `alerts.queue_depth.threshold` (500). `depths` per queue from `config/horizon.php` | degraded |
| `horizon` | no Horizon master supervisor (Redis queue only, else `skipped`) | degraded |
| `meilisearch` | `/health` not `available` (Scout on Meilisearch only) | degraded |
| `search_index` | last `toymart:search:lag-check` found lag or is overdue | degraded |
| `scheduler` | `system_heartbeats.scheduler` older than 5 min | degraded |
| `courier_sync` | no courier API answer for 90 min **while a courier holds shipments**; `last_success_at` always shown | degraded |
| `backup` | last successful `toymart:backup` older than 26 h | degraded |

  A heartbeat never recorded is `fail` where `monitoring.health.expect_heartbeats` is true (default: staging/production) and `skipped` elsewhere (local, CI).
- **Cheap to poll:** the report is cached for `MONITORING_HEALTH_CACHE_SECONDS` (10 s) as plain arrays; if the cache itself is down it is computed fresh. Rate-limited to 60/min per IP. Failures are logged at `warning` (scrubbed), never sent to Sentry.
- **Uptime monitor setup (needs owner):** check `/api/v1/health` every minute, alert on non-200 **or** on body not containing `"status":"ok"` (so `degraded` pages too, at lower urgency). A second check on the storefront home page.

### Heartbeats — `system_heartbeats`

One row per duty (`App\Models\SystemHeartbeat`), in the database so a cache flush cannot hide a stale backup. Recording never throws.

| Key | Written by |
|---|---|
| `scheduler` | `toymart:scheduler:heartbeat`, every minute (the single scheduler heartbeat; `toymart:launch:check` reads `SystemHeartbeat::lastSuccess(SystemHeartbeat::SCHEDULER)`) |
| `backup` | `toymart:backup` on success (failure → `last_failure_at`) |
| `backup.restore_rehearsal` | `toymart:backup:restore-rehearsal` on a pass. The rehearsal's own evidence on the backup disk (`RehearsalLog`) is also read by the alert, so a rehearsal run on staging against the same disk counts |
| `courier.<code>` | `CourierCircuitBreaker::recordSuccess` — any answer from that courier's API (poll or booking) |
| `synthetic.cart` | `toymart:synthetic:cart` |

## Business alerts

`php artisan toymart:alerts:run` every five minutes (scheduler, one server). Each check is read-only; `RecordAlertOutcome` keeps one row per alert in `monitoring_alerts` under `SELECT … FOR UPDATE` and decides: **fire** once when it starts, **remind** every `renotify_minutes` (240) while it lasts, **resolve** once when it clears, otherwise nothing — so re-runs are idempotent and de-duplicated. Notices go out after the commit, synchronously (not queued — a dead queue is one of the alerts). The command exits 1 while anything fires.

| Alert | Severity | Fires when (placeholder thresholds) | First thing to check |
|---|---|---|---|
| `no_orders` | critical | 0 orders in the last 3 **business** hours (09:00–23:00 Dhaka; night skipped when counting back). Silent outside business hours and before the first order ever | Place a test order; checkout errors in Sentry; payment gateway |
| `courier_booking_failures` | warning | ≥3 failed bookings and >10 % of bookings in 15 min | Courier status page, credentials, unmapped zones |
| `failed_jobs` | warning | >20 failed jobs in 60 min | `php artisan queue:failed`, Horizon failed tab |
| `courier_poll_silence` | warning | a courier holds shipments untouched for 90 min and its API has not answered for as long | Circuit breaker open? credentials expired? scheduler running? |
| `courier_webhook_silence` | warning | an active webhook-capable courier holds shipments untouched for 6 h and sent no webhook in 6 h | Webhook URL/secret in the courier portal |
| `sms_failures` | warning | ≥5 failed SMS and >20 % of SMS in 30 min | SMS balance, provider status (OTP login is affected) |
| `backup_stale` | critical | no successful backup in 26 h (or never) | `docs/ops/backup-and-restore.md` |
| `restore_rehearsal_stale` | warning | no passed rehearsal in 35 days (or never) | Run the monthly drill |
| `horizon_down` | critical | Redis queue and no Horizon master supervisor | `supervisorctl status`, `php artisan horizon:status` |
| `queue_depth` | critical | any queue >500 jobs, or the queue backend unreachable | Workers, Redis memory |
| `disk_space` | critical | <10 % or <2 GB free on the volume holding `storage/` (not applicable if PHP cannot measure it) | Logs, `storage/app/backup-temp`; the DB host needs its own check |

Not built (from §11's list): payment webhook failures (>3 in 10 min), Meilisearch index lag as an alert (it already logs at error and shows in health), courier account balance — no balance API is confirmed for either courier (research incomplete).

**Owner channel.** E-mail to `MONITORING_ALERT_EMAILS` (comma-separated; empty = every active super admin). SMS through the configured `SmsGateway` driver to `MONITORING_ALERT_PHONES` for alerts at or above `MONITORING_ALERT_SMS_MIN_SEVERITY` (default `critical`). SMS text is Latin (cheaper per segment, rule 39) and carries no PII. Staff mail is English; Bangla strings exist in `lang/bn/monitoring.php`.

**Thresholds** live in `config/monitoring.php`; any can be overridden without a deploy by a `settings` row `monitoring.<path>` in group `system`, e.g. `monitoring.alerts.no_orders.hours` = `4`, `monitoring.alerts.channels.mail` = `"a@x.com,b@y.com"`. No admin screen yet.

**Test trigger (launch checklist: "business alerts firing on a test condition").**

```bash
php artisan toymart:alerts:test --list          # names and severities
php artisan toymart:alerts:test no_orders       # one [TEST] notice through the real channel
php artisan toymart:alerts:test all             # every alert
```

A test notice is tagged `[TEST]`, reads and fakes no data, records `monitoring_alerts.last_test_at`, and never changes an alert's state. It exits 1 if nothing was delivered (no recipient) or a channel failed.

## Sentry — all three apps

| | API | Admin SPA | Storefront |
|---|---|---|---|
| SDK | `sentry/sentry-laravel` (P3) | `@sentry/react` 11.10.0, loaded as a separate chunk only when a DSN exists | `@sentry/nextjs` 11.10.0 (server, edge, browser) |
| Off when | `SENTRY_LARAVEL_DSN` empty | `SENTRY_ADMIN_DSN` empty (no `<meta name="tm-sentry">`, no CSP origin) | `SENTRY_DSN` / `NEXT_PUBLIC_SENTRY_DSN` empty — `next.config` is not even wrapped |
| Release | `SENTRY_RELEASE` (git SHA baked into the cached config by the deploy) | same `SENTRY_RELEASE`, handed over by the server in the meta tag | `SENTRY_RELEASE`, else `VERCEL_GIT_COMMIT_SHA` |
| Environment | `SENTRY_ENVIRONMENT` | `SENTRY_ENVIRONMENT` or `APP_ENV` | `SENTRY_ENVIRONMENT` / `NEXT_PUBLIC_SENTRY_ENVIRONMENT`, else `VERCEL_ENV` |
| Sample rates | `SENTRY_SAMPLE_RATE` (1.0), `SENTRY_TRACES_SAMPLE_RATE` (0) | `SENTRY_ADMIN_SAMPLE_RATE` (1.0), `SENTRY_ADMIN_TRACES_SAMPLE_RATE` (0) | `SENTRY_*` / `NEXT_PUBLIC_SENTRY_*` (1.0 / 0) |
| Source maps | n/a | `vite.config.ts`: with `SENTRY_AUTH_TOKEN` + `SENTRY_ORG` + `SENTRY_ADMIN_PROJECT`, built `hidden`, uploaded under `SENTRY_RELEASE`, deleted from `public/build` | `lib/sentry-build.ts`: with `SENTRY_AUTH_TOKEN` + `SENTRY_ORG` + `SENTRY_STOREFRONT_PROJECT`, uploaded and deleted after upload |
| Browser transport | — | direct to the DSN origin, which `SecurityHeaders::adminPolicy` adds to `connect-src` only when set | same-origin tunnel `/api/monitoring` (outside `proxy.ts`'s matcher, covered by `connect-src 'self'`), so the storefront CSP needs no ingest origin |

Bundler-plugin telemetry is always off. The auth token is a CI/build secret only — never on the server, never `NEXT_PUBLIC_`.

**PII scrubbing (rule 20), one rule set everywhere.** API: `RedactSensitiveData` + `SentryEventScrubber`. Browser/Next.js: `@toymart/observability` (`packages/observability`, plain ESM + `.d.ts`) — `beforeSend`, `beforeSendTransaction`, `beforeBreadcrumb`, `sendDefaultPii: false`. Both mask Bangladeshi phones (`017****5678`) and **e-mail addresses (`r***@example.com`, new in P10)**, redact values under password/token/secret/api-key/authorization/cookie/credential/address and **OTP keys (`otp`, `otp_code`, `*_otp`, `verification_code` — new in P10)**, and scrub `"password":"…"`, `token=…`, `otp=…`, `Bearer …` inside free text. The JS side also drops request cookies and keeps only `user.id`.

## Logs and shipping

- Production: `LOG_STACK=json` → one JSON object per line to `LOG_JSON_STREAM` (default `php://stdout`; the provisioning template sets `/var/log/toymartbd/laravel.json`, rotated by logrotate).
- Every line: `extra.request_id` (the `X-Request-Id` nginx passes through and logs in its JSON access log — join the two on it), and **`extra.service = {app: "toymartbd-api", env, release}`** (P10, `AddServiceContext`) so lines from web, workers and scheduler filter by deploy. Redaction as above.
- Alert and synthetic events log as `monitoring.alert`, `monitoring.alert.check_failed`, `monitoring.synthetic.cart_failed`.
- **Shipping (vendor not chosen — needs owner).** Any agent that tails a JSON-lines file works: Grafana Alloy / Promtail → Loki; Vector or Fluent Bit → any backend (Loki, Elasticsearch/OpenSearch, Better Stack, Axiom, Datadog); the CloudWatch agent on AWS. Point it at `/var/log/toymartbd/*.json` (API + nginx), parse as JSON, keep `request_id`, `level_name`, `extra.service.release` as indexed labels, and retain ≥30 days. Do not add a field that could hold PII as a label.

## Synthetic add-to-cart check

`php artisan toymart:synthetic:cart`, scheduled every 15 minutes once `MONITORING_SYNTHETIC_TOKEN` is set (mint with `php artisan toymart:storefront-token`; `MONITORING_SYNTHETIC_API_URL` = the **public** API base URL so the check crosses nginx/TLS like real traffic).

Flow over real HTTP: list in-stock products (or `MONITORING_SYNTHETIC_PRODUCT_SLUG`) → read the product → `POST /cart/items` (new throwaway cart, qty 1) → `GET /cart` → `DELETE /cart/items/{id}`.

Why it is safe in production: carts reserve no stock (only orders do); no order, payment, customer, contact or consent is created, so nothing reaches the ledger, reports, abandoned-cart recovery or GA4/Meta; the empty cart expires and `toymart:carts:prune` removes it. Every request carries `User-Agent: ToyMartBD-Synthetic/1.0`, `X-Synthetic-Check: 1` and `X-Request-Id: synthetic-<uuid7>` — filter these out of access-log analytics. Result: the `synthetic.cart` heartbeat; a failure exits 1 and logs the failing step (never the token). For an external runner, run the same command from another host with the same env, or point an uptime monitor's multi-step check at the same five calls.

## Needs owner

1. Sentry: create an org and three projects (api, admin, storefront); set `SENTRY_LARAVEL_DSN`, `SENTRY_ADMIN_DSN`, `SENTRY_DSN` + `NEXT_PUBLIC_SENTRY_DSN` (Vercel), and the CI secrets `SENTRY_AUTH_TOKEN`, `SENTRY_ORG`, `SENTRY_ADMIN_PROJECT`, `SENTRY_STOREFRONT_PROJECT`. First DSN'd storefront build must be checked on staging (the wrapped Next build was not run locally).
2. Uptime monitor (any vendor): `/api/v1/health` every minute + storefront home; set `MONITORING_HEALTH_TOKEN` there if the monitor should read detail.
3. Alert recipients: `MONITORING_ALERT_EMAILS`, `MONITORING_ALERT_PHONES`; then run `php artisan toymart:alerts:test all` and confirm receipt (launch checklist).
4. Confirm or change the placeholder thresholds (business hours 09:00–23:00, 3 quiet hours, etc.).
5. `MONITORING_SYNTHETIC_TOKEN` in production.
6. Choose a log shipper / store.
