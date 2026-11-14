# Gate 5 — launch readiness review

**Date:** 2026-11-14 (prepared before any production infrastructure exists) · **Against:** `DEVELOPMENT-ROADMAP.md` Phase 9 completion criteria and the Phase 10 launch checklist · **Procedure:** `LAUNCH-CHECKLIST.md` · **Automation:** `php artisan toymart:launch:check` · **Owner operations:** `RUNBOOK.md`

**Verdict today: NO-GO.** The software is close to launch-ready: Phase 9 found no open high-severity issue, and CI covers the audits. But every launch blocker in §5 depends on the owner: infrastructure, merchant accounts, legal details and content. None of them can be closed in code. This review is re-run, and §6 signed, once §5 is empty.

How to read the status column: **Met** has evidence linked. **Partly** names what is missing. **Open** is not done. **Owner** means it waits on an owner decision or account.

---

## 1. Phase 9 completion criteria

| Criterion (roadmap §Phase 9) | Status | Evidence |
|---|---|---|
| All budgets green in CI | **Partly** | The storefront performance budget gate (LCP, CLS, INP, JS, images) runs in the CI Storefront job (`e2e/performance-budgets.spec.ts`; `docs/audits/PERFORMANCE.md` "Budgets in CI": all 5 templates green, LCP ≈1 s). **But** the JS row of UI-UX §8 (140/120 KB) is below what Next 16 + React 19 ship by themselves (≈152 KB). CI holds a ratchet ceiling (+5 %) until the owner decides P-01. |
| No open high-severity finding | **Met** | `docs/audits/SECURITY-REVIEW.md` Summary: no high; every medium fixed with a test. `SEO-AND-COVERAGE.md`: SEO-1 (high) fixed. `ACCESSIBILITY-AND-BILINGUAL.md`: no contrast failures; axe gate in CI. `LOAD-AND-CONCURRENCY.md`: deadlocks fixed. Open lows: S-12 (dev-only advisory), S-13/S-14/S-15 (owner). |
| Load test passes at 10× expected launch concurrency | **Partly** | `LOAD-AND-CONCURRENCY.md`: 50 concurrent checkouts (10×) and 20× pass on MySQL with 0 deadlocks; the harness runs in the CI PHP job "Load harness (MySQL…)". **Missing:** the owner has not confirmed the base figure (assumed 5 simultaneous checkouts, 1 × 100 bulk bookings). The run on production-like infrastructure (staging) is also still owed. |
| A full restore rehearsed from backup | **Open** | Local rehearsal passed 32/32 (`backup-and-restore.md`, 2026-11-11). The acceptance test — a restore **onto staging** producing a working app — needs a staging host and the backup bucket. Rehearsals now record their result on the backup disk, and `toymart:launch:check` (`backups.rehearsal`) fails without a passing one from the last 35 days. |

### Phase 9 task list

| Task | Status | Evidence / gap |
|---|---|---|
| Coverage completion, full 403 matrix, every error path | **Met** (coverage % not measured) | `SECURITY-REVIEW.md` S-5: 22 matrices added, Arch test `tests/Arch/AdminRouteAuthorisationTest.php`, CI step "Role matrix coverage (rule 21)". `SEO-AND-COVERAGE.md` COV-1…7: 50+ endpoint rule-16 sweep, 150+ route 404 contract. Coverage numbers need pcov (owner/lead decision). |
| Performance: profiling, N+1, indexes, caching, images, Lighthouse | **Partly** | `PERFORMANCE.md`: lazy-loading prevention on every test, big query wins, two indexes, budgets in CI. **Owed:** `EXPLAIN` at volume on staging MySQL 8.4; field CWV. |
| Security review | **Met** | `SECURITY-REVIEW.md` (S-1…S-16). |
| SEO audit | **Met** (lab) | `SEO-AND-COVERAGE.md`: 500-URL crawl, `e2e/seo-crawl.spec.ts` in CI. **Owed:** Search Console coverage and field CWV (need the domain). Owner content: SEO-12…15. |
| Mobile and cross-browser QA on real mid-range Android over 4G | **Open — owner** | Not possible without devices; checklist in `ACCESSIBILITY-AND-BILINGUAL.md` "Needs the owner". |
| Accessibility: axe + manual keyboard and screen readers, both locales | **Partly** | axe + keyboard: storefront in CI (124 audits), admin 102 audits via `npm run test:a11y:admin` (not in CI, 6 min). **Owed:** NVDA / TalkBack / VoiceOver passes (owner), forced-colours check. |
| Bilingual regression sweep | **Met** | `ACCESSIBILITY-AND-BILINGUAL.md` "Bilingual sweep"; `e2e/bangla-layout.spec.ts` in CI. Native-speaker review of Bangla copy is still owed (owner). |
| Load test | **Partly** | See criterion above. |

## 2. Phase 10 launch checklist — status today

Item ids are those in `LAUNCH-CHECKLIST.md`. "Built" means the code and the automated check exist. The item itself can only be verified on production.

| Roadmap checklist item | Ids | Status |
|---|---|---|
| SSL valid and forced | A3, A4 | Built (A3 check); A4 manual — needs the domain. HSTS sent over HTTPS (`SecurityHeaders`). |
| `APP_DEBUG=false` | A2 | Built; also forced off at boot (S-8). |
| `APP_ENV=production` | A1 | Built. |
| All secrets from the host store, none in the repo | A6, A7 | Built (presence/placeholder/permissions checks; values never printed). `.env.example` holds placeholders only (S-review). |
| Real payment transaction completed and refunded | D6, D7 | **Owner**: no gateway chosen (Gate 1 decision 5). SSLCommerz driver built against the published API, seeded inactive/unverified. A COD-only launch is possible if the owner decides it. |
| Real courier consignment booked and cancelled | D4, D8 | **Owner**: no Pathao or Steadfast merchant account. Pathao is verified against the sandbox only. Steadfast is fixture-only and blocked from production booking (rule 33a). `couriers.verified` checks the block is in force. |
| Order confirmation SMS, email and WhatsApp received | D1–D3, D9 | SMS: REVE driver built; credentials are the owner's, entered in the admin. Email: needs a provider. WhatsApp: no account — stays off, SMS carries order updates. |
| COD and online checkout both complete on a real phone | D10 | Manual; COD path is CI-tested end to end (golden path, both locales). |
| Backup taken **and restored** | C1–C4 | Built (encrypted backups, rehearsal now recorded). **Open:** off-host bucket, staging restore. |
| Rollback rehearsed | C5 | `DEPLOYMENT.md` (Phase 10 prep, deploy pipeline) — rehearsal needs the server. |
| Sentry receiving | H1, H3 | Wired, off until a DSN exists (`observability.md`). |
| Uptime checks green | H3 | Health endpoint built; monitor account is the owner's. |
| Business alerts firing on a test condition | H3 | `toymart:alerts:run` / `toymart:alerts:test` (Phase 10 prep, monitoring). |
| robots.txt allows crawling | E1 | Built (`RobotsTxt::allows`, sitemap never lists a blocked URL — SEO-9). |
| Sitemap submitted | E2, E3 | Generation built + nightly; submission needs Search Console. |
| GA4 and Meta CAPI receiving deduplicated purchase events | E4 | Built (`docs/api/tracking.md`); verification needs the owner's GA4/Meta accounts. |
| 404 and 500 pages styled | F6 | **Met in code.** Storefront 404 and 500 in EN and BN (Figma 163:9166 / 169:10286), `global-not-found` server-rendered, `global-error` keeps the locale; tests `toymartbd-app/storefront/tests/error-pages.test.ts` + `e2e/ssr.spec.ts`. Admin not-found on AdminEmptyState + router error boundary; tests `resources/js/admin/app/errors.test.tsx`. Laravel web 404/500/503 + 4xx/5xx branded, bilingual, token-only, no exception text; API stays problem+json (incl. maintenance); tests `tests/Feature/Ops/ErrorPagesTest.php`. Visual check on the real domain is manual. |
| Return, privacy, terms and contact pages published with real details | F1 | **Owner**: seeded as drafts with "PLACEHOLDER" copy; `content.policy_pages` fails until each is published in EN and BN with no placeholder. |
| Trade licence and contact information displayed as required | F2–F4, F7 | **Owner**: invoice company details, `STORE_HOTLINE`, storefront `site-config.ts` all placeholders. What must legally be displayed is unresearched — needs the owner's accountant/lawyer. |
| Staff accounts created with correct roles and trained | G1, G2, G4 | Roles, the 403 matrix and the **Staff & roles screen** (Figma B · 35, `/admin/staff`: add, change role, reset password, deactivate/reactivate, editable permission matrix; two-factor sign-in required for anyone who can see money; audited; no self-change, never zero active owners) are built — `RUNBOOK.md` §10. Accounts and each person's two-factor setup are owner work (`staff.two_factor`). Not built: sessions, shifts, branches. |
| Owner has the runbook | G5 | `RUNBOOK.md` written; owner's password-manager entries and numbers to fill. |

Not in the roadmap list but checked anyway (audits): `TRUSTED_PROXIES` (S-15, A8), no demo data (G3), courier rate cards (D5), placeholder business values (F5), scheduler heartbeat (B3), Horizon (B2), storefront token (B5).

## 3. Open owner decisions — consolidated

Collected from every progress section of `IMPLEMENTATION-PLAN.md`, from `UI-UX-DESIGN-SPEC.md` §10, from `DEVELOPMENT-ROADMAP.md` §8 and from the audits' needs-owner lists. Duplicates are merged and decided items are left out. **★ = launch blocker** (§5).

### 3.1 Legal and business

- ★ **Payment gateway** and merchant onboarding (Gate 1 decision 5). Or a deliberate **COD-only launch**. Payment window 30 min / 5 attempts are placeholders.
- ★ **Courier merchant accounts** (Pathao, Steadfast). Steadfast live verification and `fraud_check` follow the account.
- ★ **Company identity:** registered legal name, trade licence, BIN, address (EN + BN), hotline, email, WhatsApp number, social profiles. The designs disagree on the hotline (09600- vs 09610-123456).
- ★ **Policy page text** (privacy incl. "Cookies and analytics", returns, terms, delivery, contact, FAQ), in English and human Bangla, with legal review.
- ★ **What must be displayed** (trade licence etc.). Unresearched: needs an accountant or lawyer.
- ★ **Return window** 7 vs 10 days (legal).
- **VAT:** toys VAT-exempt (Figma) vs standard VAT (research). VAT/Mushak invoices (N-14), debit note vs supplementary invoice, supplier VAT/AIT. Needs an accountant.
- **WhatsApp Business** account and Meta template approval. Until then order updates go by SMS.
- Team, availability and stock model (Gate 1 decisions 3 and 7; `SYSTEM-ARCHITECTURE.md` §15).

### 3.2 Content

- ★ **Launch catalog:** products with human Bangla names and descriptions, categories, photography to `UI-UX-DESIGN-SPEC.md` §3.4. Also a minimum launch catalog size.
- ★ **Logo/wordmark** and a 1200×630 share image (SEO-12, SEO-14). Meta descriptions (SEO-13).
- Native-speaker review of all Bangla strings and of the following:
  - BN consent copy;
  - Bangla PDF and label wording;
  - amount in words (একশত/একশো, লক্ষ/লাখ);
  - the no-rider SMS;
  - trimming the review-request SMS to 2 segments (A-18);
  - the 2-segment birthday reminder.
- Title separator " | " vs " · ", and a Bangla site name in titles. Bengali vs Western digits in Bangla product names.
- Footer entries dropped until their pages exist (By age, Best sellers, Toy safety).
- Birthday offer values and lead days (off until set). Children cap 6. WhatsApp gift line.

### 3.3 Infrastructure and accounts

- ★ **Hosting** for the API, admin, MySQL, Redis and Meilisearch (one VPS vs managed MySQL), plus the storefront host (Vercel vs the same VPS), domains and DNS.
- ★ **Backup bucket** (S3-compatible, versioned) and a **staging host**: these unblock P3-CI-03, the Gate 4 UAT and the staging-scale load run.
- ★ **Transactional email provider** (SPF, DKIM, DMARC). **REVE SMS credentials** in production.
- ★ **Sentry** and an **uptime monitor**.
- **GA4, GTM, Search Console, Bing, Meta Pixel and CAPI** accounts.
- Media CDN or object storage, and a storage quota.
- Hourly binlog shipping for the 1-hour RPO.
- Production `TRUSTED_PROXIES` IPs (S-15).
- Storefront token rotation / `SANCTUM_EXPIRATION` (S-13).
- Horizon dashboard in production (S-14).
- pcov coverage in CI.
- Admin axe in CI (+6 min).

### 3.4 Product decisions

- ★ **Delivery charges and rate cards** (placeholders ৳70 / ৳100 / ৳130, free delivery from ৳3,000, ETAs, zones). One rate card serves both the customer charge and the courier cost.
- ★ **Risk:**
  - weights and thresholds;
  - the first-order hold at ৳3,000;
  - watch-listed phones hold every order;
  - status-map judgement calls;
  - the delivery-rate definition.
- Launch concurrency base (5 checkouts) and a checkout API p95 budget.
- JS budget re-baseline (P-01). Bangla font weights and budget (P-13, Fredoka 800).
- Inventory:
  - stock-adjustment approval limits (20 units / ৳5,000);
  - manager self-approval;
  - overstock formula;
  - who may mark shipped or delivered.
- Ordering:
  - order edits re-price at today's price;
  - packed orders cannot be edited;
  - risk is not re-scored after an edit;
  - partial COD;
  - coupon after a non-stackable promotion;
  - customer aggregates;
  - `cost_price_snapshot` precision.
- Tracking:
  - the purchase event fires when the order is placed, not when it is delivered;
  - `tracking_events` retention;
  - purchase value includes delivery;
  - consent banner design;
  - GTM on/off;
  - TikTok and Merchant Center out of v1?
- Marketing and content:
  - review requests treated as marketing (opt-in) or as an order update;
  - cart-recovery values and where consent is captured;
  - flash-sale `allows_coupons`;
  - `/store` landing page;
  - card price: lowest variant vs headline;
  - C2PA stripping.
- Admin and design:
  - dashboard best-sellers basis;
  - activity feed source;
  - LTV bucket edges;
  - order board default range;
  - Theme B primary in product edit;
  - `ink-*` contrast and spacing tokens;
  - media delete permissions;
  - `reviews.moderate` for content_editor;
  - export permission;
  - staff mail English-only.
- Staff & roles (B · 35): per-staff branches, sessions and shifts are not built — needed before launch? (Two-factor and editable roles were decided and built, §4.)

### 3.5 Owner work still owed from earlier gates

- **Gate 4 UAT on staging** (`UAT-GATE-4.md`).
- **Phase 5 hand reconciliation of ten real orders** (`PROFIT-RECONCILIATION.md`).
- Gate 3 storefront-stack decision (Next.js is built and tested).
- Real-device and screen-reader passes (Phase 9).

## 4. Owner decisions recorded at this gate

Fill this in at the review meeting. Each WARN that `toymart:launch:check --strict` reports must appear here with a decision, or be fixed.

| Item | Decision | Date | By |
|---|---|---|---|
| COD-only launch? (`payments.gateway`) | | | |
| WhatsApp off at launch, SMS for order updates (`whatsapp.state`) | | | |
| Placeholder values kept or changed (`business.placeholder_values`) | | | |
| Launch with manual fulfilment if no courier is verified? | | | |
| Two-factor sign-in for staff who can see money; roles editable in the admin | **Yes, both — built before launch** (TOTP + recovery codes; enforcement forced on in production; `staff.two_factor` launch check). Sessions, shifts and custom roles stay out. | 2026-11-14 | Owner |

## 5. Go / no-go — blockers

**Must be closed before launch.** Each maps to a FAIL in `toymart:launch:check` or to a manual checklist row.

1. **Production infrastructure:** server(s), domains, DNS, SSL. Then A1–A10, B1–B6 green. Covers `env.*`, `security.*`, `secrets.*`, `database.connection`, `queue.*`, `schedule.heartbeat`, `search.driver`, `storefront.token`.
2. **Backups proven:** off-host bucket (C1), nightly backup (C2), a recorded passing rehearsal (C3), **and a full restore onto staging** (C4, the Phase 9 criterion and P3-CI-03). This needs a staging host.
3. **Rollback rehearsed** on the production host (C5, `DEPLOYMENT.md`).
4. **Courier:** a verified courier account with one real booking booked and cancelled (D4, D8). Steadfast stays blocked until verified. If the owner decides to launch with manual fulfilment only, record that in §4 and accept D8 as not applicable.
5. **Payment:** a live, verified gateway with a real payment and refund (D6, D7). Or a recorded COD-only decision.
6. **Messages:** mail provider with SPF, DKIM and DMARC (D1, D11). REVE SMS live with a test in EN and BN (D2). Real order messages received (D9).
7. **Legal and company details:**
   - all six policy pages published in EN and BN (F1);
   - invoice company details, trade licence and BIN (F2);
   - hotline and domain (F3);
   - storefront contact and legal values (F4);
   - legal display requirements confirmed (F7).
8. **Business values:** courier rate cards and delivery charges (D5). Risk threshold and return window decided (F5, §4).
9. **Content:** launch catalog loaded with human Bangla, photos, logo and share image. No demo data (G3).
10. **Monitoring:** Sentry DSN and release (H1); uptime checks and business alerts verified on a test (H3).
11. **People:** owner super admin and staff with roles (G1, G2); staff trained (G4); owner holds the runbook and the password-manager entries (G5, `RUNBOOK.md` §0).
12. **Real-phone acceptance:** COD (and online, if live) checkout on a mid-range Android over mobile data, both locales (D10). The Gate 4 UAT on staging is still owed.

**Should be closed, but the owner may accept them as week-1 follow-ups.** Record each in §4:

- Search Console and Bing sitemap submission (E3).
- GA4 and Meta dedup verification (E4).
- Screen-reader passes.
- Field CWV.
- Phase 5 ten-order profit check on real orders.
- JS budget re-baseline (P-01).
- Routing the shop to its maintenance page during planned downtime.
- Admin axe in CI.

## 6. Sign-off

| | Name | Date | Result of `toymart:launch:check --strict` (attach JSON) |
|---|---|---|---|
| Developer | | | |
| Owner | | | |
