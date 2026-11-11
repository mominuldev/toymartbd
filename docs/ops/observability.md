# Observability — request ids, logs, Sentry

**Task:** P3-CI-04 · **Architecture:** `SYSTEM-ARCHITECTURE.md` §11 (Errors, Logs) · **Rule:** CLAUDE.md 20 — never log a credential, a full phone number or a full address.

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

## Follow-ups (out of scope for P3-CI-04's API half)

- Sentry for the Next.js storefront (`@sentry/nextjs`) and the React admin (`@sentry/react`), with source maps uploaded and releases tagged to the git SHA.
- Document `request_id` in the `ProblemDetails` OpenAPI schema (`app/Providers/ApiServiceProvider.php`), then `composer openapi:export`.
- `last_backup_at` on `GET /api/v1/health` (informational) — needs a field in `HealthReportResource`.
- Uptime-monitor heartbeat for the nightly backup.
