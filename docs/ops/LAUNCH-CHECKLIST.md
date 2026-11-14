# Launch checklist — verified, not assumed

**Source:** `DEVELOPMENT-ROADMAP.md` §Phase 10 "Launch checklist" plus the Phase 9 needs-owner lists (`docs/audits/*`). **Gate:** Gate 5 (`GATE-5-REVIEW.md`). **Automation:** `php artisan toymart:launch:check`.

Every roadmap item is here, in the order to do them. Each item says:

- **Verify**: how to check it;
- **Check**: the `toymart:launch:check` id that automates it, or **manual**;
- **Who**: **Owner**, **Dev**, or both;
- **Evidence**: what to write in the Evidence column. Fill in a date, a name and a link or screenshot. "Done" is not evidence.

## How to run the automated part

On the production host, as the deploy user, from the current release:

```bash
php artisan toymart:launch:check                 # human report; exit 1 if anything FAILs
php artisan toymart:launch:check --json > launch-check-$(date +%F).json   # keep this file as evidence
php artisan toymart:launch:check --only=backups --only=seo   # one area
php artisan toymart:launch:check --strict        # also fail on WARN (use on launch day)
```

Statuses:

| Status | Meaning |
|---|---|
| PASS | Verified. Nothing to do. |
| WARN | Not a blocker on its own, but a person must confirm it. Usually a value that may still be a placeholder. `--strict` fails on it. |
| FAIL | Launch blocker. The command exits 1. |
| MANUAL | Cannot be automated. The report says how to verify it. Record the evidence here. |

The command is **read-only**. It never sends a message, books a parcel, takes a payment, or contacts a provider. It prints the **names** of secrets, never their values.

**Launch rule:** `toymart:launch:check --strict` must exit 0, or every WARN must have an owner decision recorded in `GATE-5-REVIEW.md` §4. Every MANUAL row below must also have evidence.

---

## A. Environment and security

Done first, on the production host, before any data goes in.

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| A1 | `APP_ENV=production` | `php artisan about` → Environment: production | `env.app_env` | Dev | |
| A2 | `APP_DEBUG=false` | Also fails if the env says true and only the boot guard forced it off | `env.app_debug` | Dev | |
| A3 | `APP_URL` and `STOREFRONT_URL` are the public https origins | Reported automatically | `env.app_url` | Dev | |
| A4 | SSL valid and **forced** (HTTP → HTTPS 301, HSTS) | `curl -sI http://<domain>/` returns 301 to https. Certificate expiry from `openssl s_client`. Both hosts, API and shop. | **manual** `env.https_forced` | Dev | |
| A5 | Secure session cookies (Secure, HttpOnly, SameSite lax, Redis) | Reported automatically | `security.session_cookie` | Dev | |
| A6 | All secrets come from the host store, present, not placeholders; `APP_KEY` escrowed | Checks DB, Redis, backup archive password, revalidate secret, Meilisearch key. Escrow `APP_KEY` and `BACKUP_ARCHIVE_PASSWORD` in the owner's password manager. | `security.app_key`, `secrets.required` | Dev + Owner (escrow) | |
| A7 | No secrets in the repo; the shared `.env` is not world-readable | Mode is checked automatically. Also confirm `.env` is the shared, symlinked file, not one from git. | `secrets.env_file` | Dev | |
| A8 | `TRUSTED_PROXIES` lists the reverse proxy and the storefront server (audit S-15) | Reported automatically. The IPs come from the hosting choice. | `security.trusted_proxies` | Dev | |
| A9 | Config and routes cached | The deploy runs `php artisan optimize` | `app.optimized` | Dev | |
| A10 | MySQL 8.4, `utf8mb4_0900_ai_ci` (rule 34) | Reported automatically | `database.connection` | Dev | |

## B. Runtime

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| B1 | Queues and cache on Redis | Reported automatically | `queue.connection` | Dev | |
| B2 | Horizon running, one supervisor per queue | Also `php artisan horizon:supervisors` shows 7 | `queue.horizon` | Dev | |
| B3 | Scheduler firing (one cron line, one server) | `system_heartbeats.scheduler` is less than 5 minutes old | `schedule.heartbeat` | Dev | |
| B4 | Search on Meilisearch, index built | Then `php artisan toymart:search:lag-check` | `search.driver` | Dev | |
| B5 | Exactly one storefront server token | Shop pages render with real data | `storefront.token` | Dev | |
| B6 | API not left in maintenance mode | `php artisan up` after the release | `app.maintenance` | Dev | |

## C. Backups and recovery

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| C1 | Encrypted backups go **off-host** (S3-compatible, versioned bucket) | Reported automatically. The bucket itself is the owner's account. | `backups.configured` | Owner (bucket) + Dev | |
| C2 | A backup from the last 26 h exists | The 02:00 Dhaka run, or `php artisan toymart:backup` | `backups.recent` | Dev | |
| C3 | Restore rehearsal **recorded**, passed, within 35 days | `php artisan toymart:backup:restore-rehearsal --against=manifest`. Each run writes `rehearsals/<time>.json` beside the backups. | `backups.rehearsal` | Dev | |
| C4 | Backup taken **and restored** onto staging, app working (P3-CI-03) | `backup-and-restore.md` "Staging restore". Record the duration against the 4-hour RTO. | **manual** `backups.staging_restore` | Dev + Owner (staging host) | |
| C5 | Rollback rehearsed | The one-command rollback in `DEPLOYMENT.md`, timed, on production before launch | **manual** `deploy.rollback` | Dev | |

## D. Integrations and the real end-to-end order

Do D1–D6 first. Then D7–D10 on launch eve, with a real phone and a low-value real order.

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| D1 | Mail transport real, sender on the shop's domain | Reported automatically | `mail.driver` | Dev | |
| D2 | SMS provider (REVE) selected with all credentials | Then **Send test SMS** in English and Bangla at Admin → Integrations → Messaging | `sms.driver` | Owner (credentials) + Dev | |
| D3 | WhatsApp is never on without a real account | WARN while off: SMS carries order updates. That is correct until a WhatsApp Business account exists. | `whatsapp.state` | Owner | |
| D4 | At least one courier **verified**; an unverified one (Steadfast) is blocked from production booking (rule 33a) | Reported automatically. The check also confirms the block is active in this environment. | `couriers.verified` | Owner (merchant account) + Dev | |
| D5 | Courier rate cards entered by the owner, not the seeded ৳70/৳100/৳130 | Admin → Integrations → Couriers → save each rate card | `couriers.rate_card` | Owner | |
| D6 | Online gateway either live and verified, or deliberately off (COD-only launch) | WARN for COD-only. FAIL for a gateway that is active but not usable. | `payments.gateway` | Owner (Gate 1 decision 5) | |
| D7 | Real online payment completed **and refunded** | Skip only if the owner decided COD-only (record it in GATE-5 §4) | **manual** `live.payment` | Owner + Dev | |
| D8 | Real courier consignment booked **and cancelled** | Shipment card shows the consignment id, then the cancelled status | **manual** `live.courier` | Owner + Dev | |
| D9 | Order confirmation SMS, email and WhatsApp received, EN and BN | Message log shows Sent. Phone shows intact Bangla. WhatsApp only once an account exists. | **manual** `live.messages` | Owner | |
| D10 | COD and online checkout complete on a real mid-range Android over mobile data | Both locales | **manual** `live.checkout` | Owner | |
| D11 | SPF, DKIM, DMARC published | `dig TXT`. A Gmail "Show original" shows three PASS lines. | **manual** `mail.dns` | Dev (DNS access: Owner) | |

## E. SEO and analytics

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| E1 | robots.txt allows crawling, Sitemap line is https | Also open `https://<shop>/robots.txt` | `seo.robots` | Dev | |
| E2 | Sitemap generated, fresh, non-empty, https URLs | Also open `https://<shop>/sitemap.xml` | `seo.sitemap` | Dev | |
| E3 | Sitemap submitted to Search Console and Bing | Status "Success" in both | **manual** `seo.submitted` | Owner (accounts) + Dev | |
| E4 | GA4 and Meta CAPI receive **de-duplicated** purchase events | GA4 DebugView. Meta Events Manager shows "Deduplicated" (`docs/api/tracking.md`). | **manual** `analytics.dedup` | Owner (accounts) + Dev | |

## F. Content, legal and business values

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| F1 | Privacy, returns, terms, contact, FAQ and delivery pages **published** in EN and BN with real details | No "PLACEHOLDER", "lorem ipsum", "TODO:" or "TBD" left | `content.policy_pages` | Owner (text, legal review) | |
| F2 | Company name, address, hotline, trade licence and BIN set for invoices | Admin → Settings → Invoice details. Invoices snapshot these when they are issued. | `business.company_details` | Owner | |
| F3 | Store hotline and public domain in SMS and email | `STORE_HOTLINE`, `STORE_PUBLIC_DOMAIN` | `business.hotline` | Owner (number) + Dev | |
| F4 | Storefront footer contact, social links, logo, trade licence and BIN are real | `toymartbd-app/storefront/lib/site-config.ts`: no `placeholder: true`. MANUAL when the storefront source is not on the API host. | `storefront.site_config` | Owner (values) + Dev | |
| F5 | Placeholder business values confirmed: free-delivery threshold, risk rules (first-order hold ৳3,000), return window 7 days (the ৳2,000 refund approval threshold is already an owner decision) | WARN until changed or saved by a person. Record the owner's decision in GATE-5 §4. | `business.placeholder_values` | Owner | |
| F6 | 404 and 500 pages styled, in both languages | Automated tests cover the markup (`tests/Feature/Ops/ErrorPagesTest.php`, storefront `tests/error-pages.test.ts`, admin `app/errors.test.tsx`). This is the visual look on the real domain. | **manual** `content.error_pages` | Dev | |
| F7 | Trade licence and contact information displayed as required | What must be displayed is a legal question. Confirm with the owner's accountant or lawyer. | **manual** `content.legal_display` | Owner | |

## G. People and data

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| G1 | The owner's super admin account exists, and only real people have accounts | Reported automatically | `staff.super_admin` | Dev | |
| G2 | Staff accounts created with correct roles (narrowest that fits) | `RUNBOOK.md` "Add or remove a staff member" | `staff.roles` | Owner + Dev | |
| G3 | No demo or seed data: no `@toymart.test` logins, demo customers or demo products | Production is seeded with reference data only | `data.no_demo` | Dev | |
| G4 | Staff trained | Each person does one real task in their role while the owner watches | **manual** `staff.trained` | Owner | |
| G5 | Owner has the runbook | Owner has read `RUNBOOK.md` and holds the password-manager entries it names | **manual** `owner.runbook` | Owner | |

## H. Monitoring

Built by the monitoring work. See `observability.md`.

| # | Item | Verify | Check | Who | Evidence |
|---|---|---|---|---|---|
| H1 | Sentry DSN set, release = git SHA | Reported automatically | `monitoring.sentry` | Owner (account) + Dev | |
| H2 | JSON logs for the log shipper | `LOG_STACK=json`, `LOG_LEVEL=info` | `monitoring.logs` | Dev | |
| H3 | Sentry receiving, uptime checks green, business alerts firing on a test condition | Trigger each alert's test condition (`observability.md`) | **manual** `monitoring.live` | Dev + Owner (alert recipient) | |

---

## Launch day order

1. A, B, C1–C3, H1–H2 green: `toymart:launch:check --only=env --only=security --only=secrets --only=app --only=database --only=queue --only=schedule --only=search --only=storefront --only=backups --only=monitoring`.
2. Content and data load (catalog, images, pages). Then F1–F5 and G1–G3.
3. D1–D6, then the real order run D7–D10. Record the order numbers here.
4. C4, C5, D11, E3, E4, H3, F6, F7, G4, G5. Then fill in the manual evidence.
5. `php artisan toymart:launch:check --strict --json > launch-check-<date>.json` exits 0, or every WARN has a recorded decision.
6. Owner signs Gate 5 (`GATE-5-REVIEW.md` §6).
