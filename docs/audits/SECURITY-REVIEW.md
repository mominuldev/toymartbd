# Security review — Phase 9 batch 1

**Date:** 2026-11-14 · **Scope:** our own code — Laravel API (admin, storefront, webhooks, web), admin SPA shell, Next.js storefront. **Method:** a defensive read and grep pass over every area in the brief, with live probes through Pest and Vitest. No external service was called.

Severity: **high** means exploitable now for data or money. **Medium** means a real weakness that needs either a precondition or a mistake elsewhere. **Low** means hardening or hygiene. A finding is closed only when it has a fix and a test, or has been escalated.

## Summary

| # | Sev | Finding | Status |
|---|---|---|---|
| S-1 | medium | No security headers on either app (no nosniff, no clickjacking protection, no CSP, no HSTS) | **fixed** |
| S-2 | medium | Admin sign-in was limited per IP only, so a botnet could spread guesses at one staff account | **fixed** |
| S-3 | medium | Coupon apply had only the 120/min storefront group limit, so codes (including personal birthday codes) could be guessed | **fixed** |
| S-4 | medium | `PUT /cart/contact` was unthrottled, and every contact can later receive a recovery SMS (an SMS-pumping lever) | **fixed** |
| S-5 | medium | Rule 21 was enforced by convention only. 22 admin routes had no 403 role-matrix test, and a matrix test that silently skipped hid 6 of them | **fixed**: matrix tests plus an enforced registry |
| S-6 | medium | The session cookie was not `Secure` unless `SESSION_SECURE_COOKIE` was set | **fixed**: secure by default in production |
| S-7 | low | CORS used the framework default `allowed_origins: *` on `api/*` | **fixed**: closed by default |
| S-8 | low | `APP_DEBUG=true` in production would put exception messages, which can hold SQL with PII, into 5xx problems | **fixed**: forced off at boot and logged as critical |
| S-9 | low | CSV formula guard missed leading tab and CR (`CsvReport`, `ProductSpreadsheet`) | **fixed** |
| S-10 | low | Laravel's signed `/storage/{path}` GET and PUT routes were registered for the private disk but never used | **fixed**: `serve => false` |
| S-11 | low | The storefront has no script CSP. Only `frame-ancestors`, `base-uri` and `object-src` are set | open: follow-up (see below) |
| S-12 | low | Dev-only npm advisory (minimatch ReDoS through eslint) | documented, not shipped |
| S-13 | low | The storefront server token never expires (Sanctum `expiration` is null) | needs owner: rotation policy |
| S-14 | info | Horizon has no `viewHorizon` gate, so its dashboard is reachable in `local` only | needs owner, if wanted in production |
| S-15 | info | Per-IP storefront limits depend on `TRUSTED_PROXIES` naming the Next.js server | needs owner: deploy config |
| S-16 | low | The storefront server budget (agent 5's SEO-1) also applied to writes that arrived without a forwarded IP | **fixed**: GET/HEAD only |

No high findings. Every medium is fixed and has a test.

---

## Authorisation (rule 21)

### Inventory

- `routes/api_v1_admin*.php` has 346 named admin routes. 4 are exempt: `ping`, `auth.login`, `auth.logout` and `auth.me`, which are public or act on the caller's own session. The other 342 each have a 403 role-matrix test. The registry holds 343 names because `auth.me` is also reached by a matrix test (the any-staff case).
- Every admin route except `ping` and `auth.login` sits behind `auth:sanctum` and `EnsureActiveStaffSession`. The Arch test asserts this.
- Authorisation happens in Policies, called from a FormRequest `authorize()` or from `$this->authorize()` in the controller.

### How rule 21 is now enforced (S-5)

The goal was a check that is maintainable, cannot be satisfied by hand-editing, and runs on every `php artisan test`. It has three parts.

1. **Registry plus Arch test.** `tests/Support/role-matrix-routes.php` lists every admin route name that has a matrix test. `tests/Arch/AdminRouteAuthorisationTest.php` fails in these cases:
   - an admin route is neither in the registry nor in `RoleMatrixCoverage::exempt()`;
   - a registry name no longer exists;
   - the registry is unsorted;
   - a non-public admin route lacks the staff-session middleware.

   The failure message tells the developer exactly what to do. The test reads the live route table, so a new route fails CI the day it is added.
2. **Recording, so the registry cannot lie.** `RoleMatrix::assertFor()` and `RoleMatrix::assertForbidden()` append each request's route name to `$ROLE_MATRIX_COVERAGE_FILE` when that variable is set (`tests/Support/RoleMatrixCoverage.php`). CI sets it on the `Pest (SQLite)` step. A new step then runs `php scripts/role-matrix-coverage.php verify <log>`, which fails if any registry name was never reached by a matrix assertion. `write <log>` regenerates the registry.
3. **Upkeep-free sweep.** `tests/Feature/Security/AdminRouteSweepTest.php` walks every admin route. A guest gets 401 everywhere except the public routes. A signed-in staff account with **no role** never gets a 2xx or a 422 except on its own-session routes. On this sweep 161 routes stopped at their policy (403) and the rest at a missing binding (404). The sweep sends an `Idempotency-Key` so that idempotent writes reach their policy.

Why this design: a static regex over test sources could not resolve the URLs the tests build at run time (`"/api/v1/admin/blog/{$path}/{$id}"`). A run-time recording alone needs the whole suite to finish before anything can be checked. The registry gives a fast, filter-safe check, and the recording proves the registry is honest.

### Gaps found and closed

These now have matrix tests in `tests/Feature/Security/RoleMatrixGapsTest.php`:

- `dashboard` and `dashboard.export` (export needs dashboard.view plus reports.export);
- `seo.serp-preview`, `seo.redirects.import` and `seo.redirects.destroy`;
- `shipments.show`;
- `finance.settlements.confirm`;
- `finance.expenses.summary`, `.reject`, `.pay` and `.receipt.{show,store,destroy}`;
- `finance.expense-categories.{update,destroy}`.

`tests/Feature/Content/Blog/BlogRoleMatrixTest.php` had a bug. It created its target through the API *after* the matrix, while still acting as the last role, a viewer, so it got a 403. That made `$id` null, and an `if ($id !== null)` then **silently skipped** the show and update matrices for blog categories, tags and authors. The test now creates its target directly.

### Storefront scoping (IDOR) and order tracking (rule 22) — verified, no change

- Account endpoints resolve the customer from the token and look up every child resource inside that customer's own rows. Another customer's id or number is a 404. Tests: `AccountApiTest` (orders, addresses), `BirthdayConsentApiTest` (consents, children), `CartApiTest` ("does not let one cart touch another cart's line").
- `POST /orders/track` requires the order number plus the last 4 digits or the full phone, compared with `hash_equals`. A wrong phone gets the same 404 as an unknown number. Attempts are limited per IP plus order, and per order across IPs. Tests: `OrderLookupApiTest`, `OrderTrackingTimelineTest`.
- Token separation holds. The storefront server token (`storefront:read`, owned by an inactive roleless service account) is refused by the admin API (`EnsureActiveStaffSession`). A customer token (`customer` ability) cannot call `storefront:read` routes.

## OWASP pass

| Area | Result |
|---|---|
| Mass assignment | Every model has `$fillable` or `$guarded = ['id']`. Only the projections `OrderFinancial` and `DailyFinancialSummary` use `$guarded = []`, and only domain projectors write them. Controllers pass `validated()` DTOs to Actions; no `create($request->all())` exists. OK. |
| SQL from input | Every `*Raw` call either binds its values or interpolates code constants. Sorts go through allowlists (`allowedSorts`, `SORTS` + `match`); an unlisted sort is a 400. `LIKE` input is escaped with `addcslashes`. OK. |
| XSS — API | Rich text is sanitised server-side with `symfony/html-sanitizer` (`ProductDescription`: an element allowlist, `http/https/mailto` links only). The only Blade `{!! !!}` is the mail font stack, a code constant. OK. |
| XSS — storefront | Every `dangerouslySetInnerHTML` is fed through `sanitizeDescription()` again (defence in depth). JSON-LD escapes `<`. OK. |
| XSS — admin | No `dangerouslySetInnerHTML`. The admin CSP now blocks injected scripts too (S-1). |
| SSRF | Outbound HTTP goes only to env-configured base URLs (couriers, SSLCommerz, SMS, GA4, Meta, storefront revalidation). Admin-entered IDs (GA4 measurement id, pixel id) are regex-validated. No endpoint fetches a URL a user supplies. `og_image` URLs are stored and rendered, never fetched. OK. |
| Open redirect | The storefront's `safeNext()` accepts local, allowlisted paths only. External redirect targets exist only in staff-managed SEO redirects, which need seo.manage and are https only. OK. |
| CSRF | The admin uses a Sanctum cookie session; every write needs `X-XSRF-TOKEN` (`CsrfTest`). OK. |
| CORS | S-7, fixed. |
| Session/cookies | Admin session: `http_only`, `same_site=lax`, and now `secure` by default in production (S-6). Storefront cookies are `httpOnly`, `sameSite=lax`, and `secure` in production (`lib/account/route.ts`). |
| Headers | S-1, fixed. |
| Sanctum | The storefront token has one ability and no expiry (S-13). The customer token is issued with an explicit `expiresAt`. |

### S-1 — Security headers (fixed)

- **Laravel:** `app/Http/Middleware/SecurityHeaders.php` is appended to the global stack in `bootstrap/app.php`.
  - **Every response:** `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, and a `Permissions-Policy`. HSTS is added over HTTPS only.
  - **`api/v1/*`:** `default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'`.
  - **Admin shell:** a per-request **nonce CSP**:
    - scripts: `'self'` plus the nonce, with no `unsafe-inline` and no `unsafe-eval`;
    - styles: `'self' 'unsafe-inline'` (for React style props) plus fonts.bunny.net;
    - images: `https:`, plus `http:` outside production;
    - `frame-src` allows the storefront origin, for the live homepage builder;
    - `frame-ancestors 'none'`;
    - while `public/hot` exists, the Vite dev origin and its websocket are added.

  The one inline script in `admin.blade.php` (the theme set before first paint) now carries the nonce, and Vite's tags get it through `Vite::useCspNonce()`. A header already set by a response is kept.
- **Storefront:** `lib/security-headers.ts`, applied to every route by `next.config.ts` `headers()`:
  - `frame-ancestors 'self' <admin origin>`, so only the admin builder can frame a page;
  - `base-uri 'self'`, `object-src 'none'`;
  - `nosniff`, a referrer policy, a permissions policy and HSTS.

  `X-Frame-Options` is deliberately not sent, because it cannot allow the admin origin. `headers()` is evaluated at **build** time, so `ADMIN_URL` or `API_URL` must be set for `next build`.
- **Tests:** `tests/Feature/Security/SecurityHeadersTest.php` (API, problem responses, HSTS only on HTTPS, admin nonce CSP and nonce on the inline script, no `unsafe-inline` for scripts, headers kept when already set, CORS, secure cookie, no `/storage` routes); `toymartbd-app/storefront/tests/security-headers.test.ts`.
- **Risk to watch:** the admin CSP is verified by Pest only. The admin Playwright E2E, which runs in CI, loads the real built bundle under it. If a third-party script is ever added to the admin, it must be added to `script-src`.

### S-11 — Storefront script CSP (open, low)

A script CSP for Next needs a nonce minted in `proxy.ts` and threaded to `<Script nonce>`. That also makes every page dynamic, which is a performance trade-off with ISR (agent 3's area), and the consented GA4/Meta tags load third-party scripts. The sanitiser runs twice, so the current XSS exposure is low. This is recommended as its own task with a performance check.

## Webhooks (rules 19, 24c) — verified, no change

| Provider | Verified before parse/write | Comparison | Authoritative state | Replay |
|---|---|---|---|---|
| SSLCommerz IPN | `SslcommerzSignature::verify` on the raw POST, before any write. An optional source-IP allowlist runs before that. | `hash_equals` | `ProcessSslcommerzReceipt` re-queries the validation API; the body is never trusted. | `webhook_receipts` UNIQUE (provider, event id); a replay is a 200 no-op. |
| Pathao | `X-PATHAO-Signature` against the stored secret (a plain shared secret, rule 24c). An empty secret refuses. | `hash_equals` | `ProcessCourierWebhook` re-queries `status()`. | `courier_webhook_receipts`. The event id is the provider's, or the sha256 of the raw body. |
| Steadfast | `Authorization: Bearer <secret>` is required, and the HMAC `X-Signature` is checked when present. | `hash_equals` | re-query | same |

- Unknown provider: 404, nothing written. Bad signature: 401, nothing written. Stored bodies drop phone, name, address and email keys.
- Tests: `OnlinePaymentFlowTest`, `IpnRetryTest`, `CourierStatusIngestionTest`, `SteadfastWebhookAndHistoryTest`, `PathaoWebhookAckTest`.
- Note (low, accepted): Steadfast's HMAC is optional because only third parties describe it. The Bearer secret is required, and the body only triggers a re-query anyway.
- Note (low, accepted): the SSLCommerz FormRequest validates field *shape* (bounded strings) before the signature check. It writes nothing and parses nothing into domain objects.

## Uploads — verified, no change

- **Images** (`ImagePipeline`) are typed by content, never by extension or client MIME: finfo plus magic bytes must agree. SVG and polyglots are refused (`<?php`, `<script`, `<svg`, `<html`… markers). Dimensions are capped, and images are decoded and **re-encoded** to fresh renditions.
- **PDFs** (certificates, media documents): `%PDF-` magic is checked, then inspected. They are served as downloads only.
- **Expense receipts** must be an image or a PDF by content. They are stored on the private disk and downloaded with `nosniff` and `private, no-store`.
- **Imports:** products are CSV/XLSX with an extension plus MIME check, `max` size and a 5,000-row cap; settlements are 5 MB CSV/XLSX; redirects are 1 MB text/CSV.
- Storage keys are generated server-side (ULID), so a client filename never becomes a path (no traversal).
- **Size limits:** 10 MB for media, images, certificates and receipts.
- **Exports:** all cells are written as strings. The formula guard now also covers leading tab and CR (S-9; test `tests/Unit/Support/CsvReportFormulaTest.php`). `Reporting\Exports\Spreadsheet` already covered them.

## Rate limits

| Endpoint | Limiter | Test |
|---|---|---|
| Admin sign-in | `login`: 5/min per IP; **new:** 10 per 15 min per account across IPs (S-2) | `RateLimitInventoryTest`, `AdminSessionTest`, `RateLimitTest` |
| OTP send | `otp`: 3 per 10 min per phone; `otp-ip`: 30/h per IP | `OtpAuthTest`, `RateLimitTest` |
| OTP verify | `otp-verify`: 10 per 10 min per phone; the code also burns after 5 guesses | `OtpAuthTest` |
| Order tracking, cart restore | `order-track`: 5 per 10 min per IP + order; 20 per 10 min per order | `OrderLookupApiTest` |
| Order confirmation | `order-lookup`: 30/min per IP | inventory |
| Checkout, payment start/return | `checkout`: 10/min per cart | `RateLimitTest` |
| **Coupon apply** | **new** `coupon`: 10 per 10 min per cart, 60 per 10 min per IP (S-3) | `RateLimitInventoryTest` |
| **Cart contact** | **new** `cart-contact`: 60/h per IP (S-4) | `RateLimitInventoryTest` |
| Reviews | `reviews`: 10/h per customer | inventory |
| Search suggest, tracking relay | own limiters | inventory |
| Storefront / admin groups | 120/min per IP; 300/min per user | `RateLimitTest` |
| Webhooks | none, by design: signature plus idempotency | `RateLimitTest` |

### Review of SEO-1: the storefront server budget (agent 5, `STOREFRONT_SERVER_PER_MINUTE = 2400`)

**Verdict: acceptable after one hardening fix (S-16, low, fixed).**

- **How a "server read" is identified.** The TCP peer (`REMOTE_ADDR`) must be in `TRUSTED_PROXIES` (`isFromTrustedProxy()`), and the request must carry no `X-Forwarded-For`. No token and no client-controlled header can opt a request in, because the deciding test is the *absence* of a header.
- **A browser or attacker cannot reach the bucket directly.** It would need its TCP connection to come from a trusted-proxy address. `TRUSTED_PROXIES` refuses wildcards and defaults to none. A forged `X-Forwarded-For` from an untrusted peer is ignored, so the attacker keeps their own 120/min bucket (test: "never lets a direct client claim the server budget…").
- **What a shopper can still do.** They can make Next miss its ISR cache (distinct listing URLs) and so spend the shared 2,400/min budget through Next. That is bounded and per Next server, and it is strictly better than the previous one-bucket 120/min, which a crawler could exhaust. Edge-level bot protection is the real answer (needs owner).
- **S-16, fixed.** Next forwards the shopper IP on every uncached call (`lib/api.ts` `forwardedFor()`), but only when it can determine one (`X-Real-IP` or the rightmost `X-Forwarded-For` from our edge). If the edge sends neither, a shopper **write** (checkout, OTP, coupon, cart) would have arrived without `X-Forwarded-For` and borrowed the 2,400 budget. The bucket now applies to GET/HEAD only (`isMethodSafe()`), and an ISR read is always a GET. Test: "gives the whole-shop server budget only to GET reads from a trusted proxy, never to a write".
- **Owner deploy requirement** (same as S-15): the reverse proxy in front of Next must set `X-Real-IP`. Otherwise every shopper-driven per-IP limit keys on the Next server.

`tests/Feature/Security/RateLimitInventoryTest.php` asserts that every endpoint above carries its named limiter, so removing one fails CI. There is no customer-facing contact-form endpoint today; the contact page is static CMS content.

## Secrets and PII

- **Credentials** use `encrypted` / `encrypted:array` casts: `courier_providers.credentials`, `courier_providers.webhook_secret` and `payment_providers.credentials`. Integration secrets kept in settings (SMS, WhatsApp, GA4, Meta) are stored encrypted by their Actions. `#[SensitiveParameter]` is used on signature helpers. OK.
- **Logs:** every channel taps `RedactLogs`. It masks phone numbers in messages and context to `017****5678`, redacts password/token/secret/key/cookie/authorization/address keys, and strips Bearer/Basic values. Direct log calls pass masked phones only. Sentry has `SentryEventScrubber`.
- **Job payloads:** jobs serialise model ids. `SendLoginOtp` carries the phone and code, so it is `ShouldBeEncrypted`. OK.
- **`.env.example`** has placeholders only (`DB_PASSWORD=secret` and `MEILISEARCH_KEY=local-master-key-change-me` are local defaults).
- **`APP_DEBUG`** defaults to false in `config/app.php`, and is now forced off in production (S-8; `app/Support/ProductionSafety.php`, `tests/Unit/Support/ProductionSafetyTest.php`).
- Note (low): `RedactSensitiveData` does not mask email addresses. Rule 20 names phones, addresses and credentials, so this was left as is.

## Dependencies

- `composer audit`: no advisories.
- `npm audit --omit=dev` (root workspaces, everything that ships): **0 vulnerabilities**.
- `npm audit` including dev: 3 moderate, all one advisory, GHSA-g3hv-75wg-m249 (minimatch ReDoS through `eslint` and `@eslint/config-array`). It is dev tooling, never shipped or run on untrusted globs. The only fix npm offers is a semver-major eslint change, so it is not taken here (S-12).

## Needs owner

- **S-13:** decide a rotation schedule for the storefront server token (`php artisan toymart:storefront-token --rotate`), or set `SANCTUM_EXPIRATION` with a rotation runbook.
- **S-14:** if Horizon should be reachable in production, define a `viewHorizon` gate (e.g. settings.manage_integrations). Today it is local-only.
- **S-15:** production `TRUSTED_PROXIES` must list the Next.js server(s). Otherwise every shopper shares one per-IP bucket for OTP, tracking, coupon and cart-contact limits.
- Set `SESSION_SECURE_COOKIE` explicitly if production ever terminates TLS somewhere other than in front of Laravel. The default is now secure in production.
- Set `ADMIN_URL` (or `API_URL`) for `next build`, so that the storefront's `frame-ancestors` allows the admin builder.

## For other areas

- **Agent 3 (performance):** S-11, the storefront script CSP, interacts with ISR and static rendering. Raise it if nonce-based CSP is taken on.
- **All agents:** a new admin route now needs a RoleMatrix test **and** its name in `tests/Support/role-matrix-routes.php`, or `tests/Arch/AdminRouteAuthorisationTest.php` fails.
