# Toy Mart BD — Development Roadmap

**Status:** Draft for owner approval · **Date:** 2026-11-07

---

## 1. How to Read These Estimates

Estimates are in **dev-days**: one focused working day of one developer, including that task's own tests. They exclude meetings, content entry, product photography and waiting for third-party approvals.

They assume:
- One **senior full-stack** developer (Laravel + React) — the critical path
- One **mid-level frontend** developer (React/Next.js)
- One **designer**, roughly half-time, front-loaded into Phase 2
- The owner available for decisions within 24 hours and for UAT in Phases 4–9

A **15% contingency** is already included in each phase total. It is not optional padding — it is the historical cost of courier API surprises, gateway onboarding delays and content churn.

### Headline numbers

| Scope | Dev-days | Calendar (team above, parallelised) |
|---|---|---|
| **MVP — launchable, revenue-generating** | **≈ 164** | **≈ 16–18 weeks (4–4.5 months)** |
| Full scope as briefed | ≈ 256 | ≈ 25–29 weeks (6–7 months) |
| Phase 2 follow-on (full minus MVP) | ≈ 92 | ≈ 10–12 weeks after launch |

**Revised 2026-11-08** following two owner decisions: Bangla UI moved into MVP (+16 dev-days, +3 designer-days), and development to proceed without courier merchant accounts (Steadfast live verification moves out of MVP, −2 days; a compensating verification-call control moves in, +1 day). Both changes are reflected in the phase tables below.

**Recommendation: ship the MVP first.** The admin modules the business genuinely cannot operate without — orders, inventory, courier booking, risk screening, real P&L — are all inside the MVP. What is deferred is breadth, not capability: campaign tooling, segmentation, bundles, multi-warehouse, Bangla *admin* UI, advanced analytics. A store earning money in month four and improving is worth far more than a complete store in month seven, and the deferred list will be re-prioritised by what real customers actually do.

---

## 2. Phase Dependency Graph

```
P1 Research ──────────────┐
                          ├──▶ P3 Foundation ──▶ P4 Core commerce ──┬──▶ P6 Courier & risk ──┐
P2 Design system ─────────┘                          │               │                        │
   (blocked on Figma)                                ├──▶ P5 Inventory & ops ────────────────┤
                                                      │                                        ├──▶ P8 Reporting ──▶ P9 Testing ──▶ P10 Launch
                                                      └──▶ P7 CMS, SEO & marketing ───────────┘
```

P5, P6 and P7 are largely independent of each other and can run in parallel across developers. P8 needs all three. **P2 is on the critical path for everything customer-facing and is currently blocked** — see the gate below.

---

## 3. Phase Detail

### Phase 1 — Research & Product Discovery · 8 dev-days · ✅ substantially complete

**Objective.** Establish what competitors actually do, what BD operational software offers, what the courier and payment APIs require, and where the real opportunity is — before any design or code.

**Tasks.** Competitor audit of 9 BD toy sites plus marketplace baseline · AppByte benchmarking with confirmed/inferred separation · Pathao and Steadfast API reference · payment-gateway comparison and recommendation · COD economics and return rates · SMS/WhatsApp options · address dataset and phone validation · legal and VAT requirements · feature gap analysis · MVP definition.

**Deliverables.** `COMPETITOR-ANALYSIS.md` · `FEATURE-MATRIX.md` · `PRODUCT-REQUIREMENTS.md` · `SYSTEM-ARCHITECTURE.md` · `DATABASE-DESIGN.md` · `UI-UX-DESIGN-SPEC.md` · this roadmap · `IMPLEMENTATION-PLAN.md` · `docs/_research/*` · `CLAUDE.md` · `AGENTS.md`

**Completion criteria.** Every claim about a competitor or an external API is either sourced or explicitly marked unverified · MVP boundary agreed by the owner · roadmap approved.

> ⛔ **Gate 1 — owner approval of this roadmap and the MVP boundary.** Nothing in Phase 3 onward starts until this is signed off.

---

### Phase 2 — UI/UX & Design System · 28 dev-days (designer-led) · ⛔ BLOCKED

**Blocker.** The supplied Figma file is empty — one page, zero nodes, no styles, no variables. See `UI-UX-DESIGN-SPEC.md` §1. The owner must either share the correct file, approve the written specification as the direction, or supply reference material.

**Objective.** A published Figma library and every MVP screen designed, so that frontend work is assembly rather than invention.

| Task | Days |
|---|---|
| Brand foundations: logo handling, palette confirmation, type scale, spacing, elevation, motion — as Figma **variables** with light/dark modes | 4 |
| Tier 1 primitive components as variant sets, all states, bound to variables | 6 |
| Storefront MVP screens, mobile 390 + desktop 1440 | 8 |
| Admin MVP screens, 1440 light + dark, plus tablet | 5 |
| Prototyped flows (checkout, search, order → courier → delivered) and handoff review | 2 |
| **Bangla variants of the key storefront screens** — Anek Bangla at 1.15× line-height, longer string lengths in buttons and labels, language toggle, and the screens where Bangla text reflows differently (nav, filters, checkout, PDP) | 3 |

**Dependencies.** Gate 1. Logo and brand assets. Bangla-at-launch decision.
**Deliverables.** Published Figma library · all MVP screens · tokens exported to a Tailwind config · component inventory mapped to React file paths · prototypes.
**Testing.** Contrast audit of every token pair against WCAG 2.2 AA · touch-target audit · 320px and 200%-zoom reflow check · design review against the brief's brand adjectives.
**Completion criteria.** No screen contains a hardcoded hex or raw rectangle · every Tier 1 and Tier 2 component exists as a component set · tokens exported and committed · owner sign-off.

> ⛔ **Gate 2 — design sign-off.** Storefront and admin UI work cannot start on unapproved designs without guaranteeing rework.

---

### Phase 3 — Technical Foundation · 18 dev-days

**Objective.** A running skeleton of all three applications with auth, permissions, CI and the deployment pipeline proven end to end — so that from Phase 4 on, every feature ships on rails rather than on scaffolding.

| Task | Days |
|---|---|
| Monorepo layout, Laravel 13 install, MySQL/Redis/Meilisearch local via Docker, Horizon | 2 |
| Domain directory structure, `deptrac` boundary rules, `Money`/`PhoneNumber`/`Slug` support classes | 2 |
| **i18n foundation**: Laravel locale resolution and lang files, `Accept-Language` plus explicit-locale API contract, Next.js locale routing with English at the root and Bangla at `/bn/`, locale-aware formatters, and a lint rule failing any hardcoded user-facing string | 3 |
| Reference migrations: users, roles, permissions, divisions/districts/upazilas seeded with Bangla names, settings, activity log | 2 |
| Sanctum auth: admin cookie session, storefront server token, customer token; policies scaffold; the 403 role test matrix | 2 |
| API v1 skeleton, Resource/FormRequest base classes, RFC 9457 error handler, idempotency middleware, rate limiters, OpenAPI generation | 2 |
| React admin: Vite, TS strict, Tailwind from Phase 2 tokens, router, TanStack Query, AppShell, login, one real CRUD screen proving the stack | 2 |
| Next.js storefront: App Router, server API client, ISR + revalidation webhook, one real catalog page proving the stack | 1.5 |
| CI: Pint, PHPStan L6, deptrac, Pest, ESLint, tsc, Vitest, Playwright smoke; staging deploy; backup and restore rehearsal | 1.5 |

**Dependencies.** Gate 1 · Phase 2 tokens (the Tailwind config only — not full screens).
**Deliverables.** Three apps running locally and on staging · login working with roles · OpenAPI published · CI green · one restore rehearsed from backup.
**Testing.** Auth feature tests per role · 403 matrix · idempotency replay test · health endpoint · deptrac passes · staging smoke.
**Completion criteria.** A developer can clone, run one command, and get a working environment with seeded Bangla reference data · a commit to `main` deploys to staging automatically · a database restore has been performed successfully at least once.

> ⚠️ **Gate 3 — storefront stack decision.** Evaluate honestly whether the team can sustain a Next.js storefront alongside a Laravel API. If not, switch to Laravel Blade + Livewire **now**. Everything else is unchanged. Deciding here costs ~2 weeks; deciding in Phase 7 costs ~6. See `SYSTEM-ARCHITECTURE.md` §3.

---

### Phase 4 — Core E-commerce · 52 dev-days

**Objective.** A customer can find a product and buy it; staff can manage the catalog and process the resulting order.

| Task | Days |
|---|---|
| Catalog schema and models: products, variants, attributes, categories (nested set), brands, images, relations | 4 |
| Catalog admin: product CRUD with variant matrix editor, image uploader with alt text, category tree, brands, attributes, duplication | 7 |
| Bulk CSV/Excel import and export with a row-level error report | 3 |
| Media pipeline: upload, re-encode, AVIF/WebP variants, CDN delivery | 2 |
| Catalog API: listings, filters (**age-range filter included — the category's highest-value facet**), sorting, cursor pagination, PDP payload | 3 |
| Meilisearch: index, sync jobs, Bangla + Banglish synonyms, typo tolerance, faceting | 3 |
| Storefront: home, category, brand, search, PDP, gallery, variant selector, zero-results | 8 |
| Cart: server-side cart, pricing pipeline, delivery-charge resolver, coupon application, stock validation | 4 |
| Checkout: guest-first single page, cascading address selects, delivery estimator, COD + online, idempotent order placement with stock reservation | 6 |
| Customer accounts: phone + OTP auth, order history and detail, addresses, wishlist, public order tracking by number + phone | 4 |
| Order management admin: order board, order detail, status transitions, manual/phone order creation, order edit, internal notes, activity log | 6 |
| Payment integration: gateway driver for the recommended provider, COD driver, webhook verification, retry and failure handling | 4 |
| Transactional notifications: order placed, confirmed, shipped, delivered via SMS and email, **with Bangla and English templates** | 3 |
| **Bangla storefront UI**: every string through i18n, language toggle persisted per visitor, Bangla suggested (never forced) on a first visit from Bangladesh, and a Bangla layout/overflow pass across every MVP screen | 6 |

**Dependencies.** Phase 3 · Gate 2 for the storefront and admin screens · **human-written Bangla copy for all UI strings and the launch catalogue — this is now the critical-path content dependency and it belongs to the owner, not the developers** · a payment-gateway sandbox account (**start onboarding in Phase 1 — this is the most common schedule blocker, as merchant approval needs a trade licence and bank account and can take weeks**).

**Deliverables.** Working storefront through to a placed order · catalog and order admin · payment integration verified in sandbox · notifications sending.

**Testing.** Pricing and discount-stacking unit tests · concurrent-checkout oversell test · idempotent double-submit test · guest and registered checkout feature tests · webhook signature valid/invalid/replayed · Playwright golden path browse → PDP → cart → COD checkout → confirmation · Bangla product names throughout.

**Completion criteria.** The owner can add a real product with variants, images and SEO, and a real customer can buy it COD on a phone and receive an SMS · **the entire purchase journey completes in Bangla as well as English, with no untranslated string and no clipped or overflowing Bangla label on any screen at 390px** · stock reserves correctly and releases on cancel · the two correctness invariants hold.

> ⛔ **Gate 4 — UAT by the owner on staging with real products.** This is the first point where the plan meets reality; expect and schedule a feedback round.

---

### Phase 5 — Inventory & Business Operations · 35 dev-days

**Objective.** The owner knows exactly what stock exists, what it cost, and what the business actually earned — not price minus cost.

| Task | Days |
|---|---|
| Stock levels and the append-only movement ledger, with locking and the reconciliation job | 4 |
| Suppliers, purchase orders, partial receipt, landed-cost apportionment, weighted-average costing | 6 |
| Stock adjustments, damage and loss tracking, approval flow, movement history UI | 4 |
| Low-stock and out-of-stock alerts, reorder points, daily digest | 2 |
| Inventory valuation report and stock-overview screens | 3 |
| Financial ledger with the revenue-versus-cash separation, expenses and expense categories | 4 |
| Order-level financial projection, product-wise and category-wise profitability | 4 |
| P&L statement: daily, weekly, monthly, yearly, with expense breakdown | 4 |
| Supplier payments and payables | 2 |
| Returns and refunds: refund flow with approval threshold, restocking, ledger reversal, gateway refund | 4 |

**Dependencies.** Phase 4 (orders must exist before they can be costed).
**Deliverables.** Full inventory module · purchase and supplier management · P&L reports · refund handling.
**Testing.** Ledger-sums-to-level invariant · financials-equal-ledger invariant · landed-cost apportionment unit tests · weighted-average recalculation on receipt · P&L correctness with a partial refund plus a courier return · Dhaka-date bucketing on every daily report.
**Completion criteria.** A purchase can be received, its landed cost flows into a sale, and the resulting net profit figure is correct to the taka including courier charges, discounts and refunds — verified by hand against a spreadsheet on at least ten real orders.

---

### Phase 6 — Courier Integration & Order Intelligence · 24 dev-days

**Objective.** One click books a shipment; repeat fake-order callers are caught before a parcel is paid for twice.

| Task | Days |
|---|---|
| Courier Gateway abstraction, provider config with encrypted credentials, **`verified` flag per provider**, and a `NullGateway` + fixture harness that exercises the whole fulfilment flow in CI | 4 |
| **Day one: confirm whether Pathao sandbox credentials are usable without a merchant account.** If not, register on the merchant portal immediately — everything below depends on it | 0.5 |
| Pathao driver, **verified against the real sandbox**: token issue and refresh, location sync, price calculation, order creation, status; sandbox responses captured as fixtures | 4 |
| Steadfast driver, **fixture-only — no sandbox exists**: create, bulk create, status by consignment/invoice/tracking, balance. Built from its published Postman collection, shipped `verified: false`, blocked from production booking, with an "unverified provider" banner in the admin | 3 |
| `courier_locations` mapping table, sync job, and the admin screen for unmapped areas | 3 |
| One-click and bulk booking UI, label printing, charge preview, failed-booking retry queue | 4 |
| Webhook ingestion with signature verification and idempotency, plus a 15-minute reconciliation poll, normalised into one status enum | 3 |
| Customer-facing tracking timeline · courier performance report | 2 |
| Risk module: phone-keyed profiles, weighted scoring with a visible breakdown, verification queue, blacklist/whitelist. **Courier-lookup panel built but renders "provider not connected"** — Steadfast's `fraud_check` needs a merchant account | 5 |
| **Compensating control for the missing courier history**: route every first order from an unknown phone above a configurable value to the verification queue regardless of score | 1 |
| COD settlement reconciliation with variance flagging | 3 |

**Dependencies.** Phase 4 · **Pathao sandbox access.** The owner has confirmed no merchant account exists for either provider, so this phase is built against the Pathao sandbox and Steadfast fixtures. Two items are explicitly deferred out of MVP and gated on real credentials:
- **Steadfast live verification** (2 days) — run the driver against a real account, capture real fixtures, flip `verified: true`.
- **Steadfast `fraud_check` integration** (1 day) — populate `courier_fraud_lookups` once the account exists.

Neither blocks launch. Both should happen as soon as the accounts are opened, because the fraud endpoint is the single highest-leverage integration available in this market.
**Deliverables.** Both couriers integrated · bulk booking · tracking sync · risk screening · COD reconciliation.
**Testing.** Driver integration tests against recorded fixtures for success, failure, timeout and malformed response · webhook signature valid/invalid/replayed · status-mapping coverage for every documented provider status · risk-score boundary tests · unmapped-area booking is blocked with a clear message, never guessed · circuit-breaker behaviour under provider outage.
**Completion criteria.** A real order books a real consignment **through the Pathao sandbox** · status updates arrive and display · the Steadfast driver passes its full fixture suite and is correctly blocked from production · the phone-lookup screen shows our score with its breakdown, and the courier panel shows an honest "provider not connected" state rather than a zero · a COD settlement reconciles and flags a deliberate variance.

---

### Phase 7 — CMS, SEO & Marketing · 33 dev-days

**Objective.** The owner changes the homepage, publishes content and runs promotions without a developer; search engines can crawl and understand everything.

| Task | Days |
|---|---|
| CMS block system: typed blocks, ordering, scheduling, live preview, homepage builder | 6 |
| Static pages, menu builder, banners, media library, testimonials | 4 |
| Blog: posts, categories, tags, authors, related posts, buying guides | 4 |
| SEO module: per-entity meta panel with SERP preview, canonical, robots, OG/Twitter | 3 |
| Structured data: Product with Offer and AggregateRating, BreadcrumbList, Organization, Article, FAQPage, WebSite+SearchAction | 3 |
| Slug history with automatic 301s, redirect manager, XML sitemap generation, robots.txt editor, pagination SEO | 3 |
| **Per-locale SEO**: `hreflang` pairs plus `x-default` on every page, separate title/description/OG per locale, locale-partitioned sitemaps, and Bangla `name`/`description` in Product schema — **no competitor or marketplace has any of this** | 3 |
| Coupons and the coupon builder; promotions engine with priority and stacking rules; flash sales with countdown | 5 |
| Abandoned-cart detection and recovery messaging | 2 |
| Server-side tracking: GA4 Measurement Protocol, Meta Conversions API, browser/server deduplication by shared event id, consent gating, diagnostics screen | 4 |

**Dependencies.** Phase 4 · Gate 2 for CMS block designs.
**Deliverables.** Working CMS · full SEO coverage · promotions · server-side tracking with diagnostics.
**Testing.** Schema validated against Google's Rich Results requirements for every page template · title/meta/canonical regression tests on all templates · sitemap reachable and complete · slug change produces a working 301 · promotion priority and stacking unit tests · tracking deduplication verified in Meta Events Manager · consent gating blocks dispatch when refused.
**Completion criteria.** The owner reorders the homepage, schedules a campaign banner and publishes a blog post unaided · every page template passes schema validation · a product rename leaves no dead URL.

---

### Phase 8 — Reporting & Automation · 25 dev-days

**Objective.** Answers without spreadsheets, and recurring work that runs itself.

| Task | Days |
|---|---|
| Dashboard: sales, orders, customers, revenue, profit, order-status counts, low stock, best sellers, trends, date-range filtering | 6 |
| Sales analytics: trends, category and brand performance, AOV, repeat-purchase rate, conversion | 4 |
| Courier performance: delivery rate, return rate, average transit time, cost per provider | 3 |
| Customer analytics: lifetime value, cohorts, repeat rate, segmentation | 3 |
| Invoice generation: customer and supplier templates, PDF, bulk print, numbering, company snapshot | 4 |
| Nightly financial rollups, scheduled report emails, export to CSV/Excel | 3 |
| Notification automation: WhatsApp Business templates, SMS templates, review requests, birthday reminders | 2 |

**Dependencies.** Phases 5, 6, 7.
**Deliverables.** Full dashboard and analytics · invoicing · scheduled jobs and reports.
**Testing.** Report figures reconciled by hand against the ledger for a full month of seeded data · timezone correctness on every date bucket · PDF rendering with Bangla text · scheduled-job idempotency.
**Completion criteria.** Dashboard numbers match the ledger exactly · invoices render correctly with Bangla names · scheduled reports arrive on time.

---

### Phase 9 — Testing & Optimisation · 22 dev-days

**Objective.** Find the failures before customers do.

| Task | Days |
|---|---|
| Test-coverage completion: gaps in domain logic, the full 403 role matrix, every error path | 5 |
| Performance: query profiling and N+1 elimination, index verification under volume, caching, image optimisation, Lighthouse budgets met on every template | 4 |
| Security review: OWASP pass, authorisation audit, webhook verification review, upload handling, rate-limit verification, dependency audit, secret handling | 4 |
| SEO audit: crawl, index coverage, schema, Core Web Vitals field data, canonical and duplicate-content check | 2 |
| Mobile and cross-browser QA on real mid-range Android devices over throttled 4G | 2 |
| Accessibility audit: axe plus manual keyboard and screen-reader passes in Bangla and English | 2 |
| **Bilingual regression sweep**: every MVP screen in both locales at 390px and 1440px, checking Bangla string overflow, line-height, form labels, button widths, email and SMS templates, and `hreflang` correctness | 2 |
| Load test: concurrent checkout, flash-sale burst, courier bulk booking | 1 |

**Dependencies.** Phases 4–8.
**Deliverables.** Test suite at target coverage · performance budgets met · security findings resolved · audit reports.
**Testing.** This phase *is* testing. Nothing ships with an open high-severity security or accessibility finding.
**Completion criteria.** All budgets green in CI · no open high-severity finding · load test passes at 10× expected launch concurrency · a full restore rehearsed from backup.

> ⛔ **Gate 5 — launch readiness review** against the Phase 10 checklist.

---

### Phase 10 — Deployment & Launch · 10 dev-days

**Objective.** Live, monitored, recoverable.

| Task | Days |
|---|---|
| Production provisioning: server, MySQL, Redis, Meilisearch, object storage, CDN, hardening | 2 |
| Domain, SSL, DNS, email deliverability (SPF/DKIM/DMARC) | 1 |
| Production CI/CD with zero-downtime releases and a rehearsed rollback | 1 |
| Backups: automated, encrypted, off-host, with a restore rehearsal | 1 |
| Monitoring: Sentry, uptime checks, business alerts, log aggregation, health endpoint | 1.5 |
| Live payment and courier credentials, production webhook registration, end-to-end test with a real low-value order | 1.5 |
| Analytics: GA4, GTM, Search Console, Bing, Meta pixel + CAPI, sitemap submission | 1 |
| Content and data load: catalog, images, static pages, policies · final UAT · launch | 1 |

**Dependencies.** Gate 5 · live merchant credentials · final content.
**Deliverables.** Production live · monitoring active · backups verified · analytics recording · runbook written.

**Launch checklist (every item verified, not assumed).**
SSL valid and forced · `APP_DEBUG=false` · `APP_ENV=production` · all secrets from the host store, none in the repo · real payment transaction completed and refunded · real courier consignment booked and cancelled · order confirmation SMS, email and WhatsApp received · COD and online checkout both complete on a real phone · backup taken **and restored** · rollback rehearsed · Sentry receiving · uptime checks green · business alerts firing on a test condition · robots.txt allows crawling · sitemap submitted · GA4 and Meta CAPI receiving deduplicated purchase events · 404 and 500 pages styled · return, privacy, terms and contact pages published with real details · trade licence and contact information displayed as required · staff accounts created with correct roles and trained · owner has the runbook.

---

## 4. MVP Boundary

### In MVP (≈164 dev-days)
**Storefront** — home, category, brand, search with Banglish tolerance, zero-results, PDP with gallery and variants, age filtering, delivery estimator, cart, guest-first checkout, COD + one online gateway, order confirmation, public order tracking, phone+OTP accounts, order history, addresses, wishlist, static pages, blog, WhatsApp button, 404/500.
**Admin** — dashboard, product and variant management with bulk import, categories, brands, attributes, review moderation, order board and detail, manual order creation, order edit, bulk processing, stock levels and movement ledger, suppliers, purchase orders with landed cost, stock adjustment, low-stock alerts, Pathao and Steadfast booking including bulk, shipment tracking, failed-booking retry, courier location mapping, risk scoring and phone lookup, verification queue, blacklist, refunds, expenses, P&L with product-wise profitability, COD settlement, invoices, customers with LTV, coupons, flash sales, CMS homepage builder, static pages, blog, full SEO module with schema and 301 handling, server-side tracking with diagnostics, users and roles, settings.

### Deferred to Phase 2 (≈92 dev-days)
**Steadfast live verification and `fraud_check` integration (3 dev-days, gated on a merchant account — do this first)** · Bangla *admin* UI · children's profiles and birthday reminders · gift finder · bundles and buy-X-get-Y · customer segmentation and campaign tooling · abandoned-cart automation beyond basic detection · advanced analytics (cohorts, courier performance deep-dive) · storefront dark mode · damaged-goods and stock-transfer modules · multi-warehouse · testimonials · landing-page builder · additional couriers · additional payment gateways · loyalty · product Q&A · recently-viewed personalisation · schema validator UI.

**Rationale for the boundary.** Everything an order touches on its way from browse to cash in the bank is in the MVP, including the operational modules that prevent losses — risk screening, courier reconciliation, honest P&L. What is deferred either serves growth rather than operation, or is cheap to add once there is real usage data to aim it at.

**Bangla UI is now in MVP** at the owner's decision. The development cost is ≈16 dev-days; the larger half of the work is content, and it is the owner's: every product name, description, category label and policy page needs a human Bangla version before launch. Machine translation is explicitly out — beating Daraz's machine-translated Bangla is the point.

**Steadfast live verification is the one deferral that should not wait.** It is three days of work gated on a free merchant account, and it unlocks the only officially published courier-history endpoint in this market. Deferring it is a consequence of not having the account, not a judgement that it is unimportant.

---

## 5. Critical Path & Parallelisation

**Critical path:** Gate 1 → Phase 2 foundations → Phase 3 → Phase 4 → Phase 6 → Phase 9 → Phase 10.

Phase 5 and Phase 7 sit off the critical path and should be assigned to the second developer, running concurrently with Phase 6.

| Weeks | Senior full-stack | Mid frontend | Designer |
|---|---|---|---|
| 1–2 | Phase 3 foundation | Phase 3 admin + storefront skeleton | Phase 2 foundations + primitives |
| 3–6 | Phase 4 catalog, cart, checkout, payments | Phase 4 storefront screens | Phase 2 screens |
| 7–9 | Phase 4 orders · Phase 5 inventory | Phase 4 admin catalog + order screens | Phase 2 admin screens · handoff |
| 10–12 | Phase 6 courier + risk | Phase 5 inventory UI · Phase 7 CMS | Review, iteration |
| 13–14 | Phase 5 finance · Phase 8 reporting | Phase 7 SEO + marketing | — |
| 15–16 | Phase 9 testing · Phase 10 launch | Phase 9 testing | — |

**Start these in week 1, because they have external lead times that can block later phases:**
1. Payment-gateway merchant onboarding (trade licence, TIN, bank account) — this is the single most common cause of launch slippage.
2. Pathao and Steadfast merchant accounts with API credentials.
3. SMS sender-ID (masking) approval.
4. WhatsApp Business API verification and message-template approval.
5. Domain and hosting procurement.
6. Product photography against the standard in `UI-UX-DESIGN-SPEC.md` §3.4 — the owner's largest non-development workload and routinely underestimated.

---

## 6. Risk Analysis

| # | Risk | L | I | Mitigation | Owner |
|---|---|---|---|---|---|
| R1 | **Figma empty — Phase 2 blocked** | Certain | High | Resolve at Gate 1 by choosing one of the three options in `UI-UX-DESIGN-SPEC.md` §1 | Business owner |
| R2 | Payment-gateway onboarding delays launch | High | High | Begin in week 1; build COD-complete so the store can launch COD-only if needed | Owner + lead |
| R3 | Three-app complexity exceeds team capacity | High | High | Honest assessment at Gate 3; Plan B is Laravel Blade + Livewire with everything else unchanged | Lead |
| R4 | Courier API behaviour differs from documentation | High | Medium | Gateway abstraction, raw payload retention, reconciliation poll, manual consignment entry as fallback | Lead |
| R5 | Product content and photography not ready at launch | High | Medium | Define the standard in Phase 2; agree a minimum launch catalog size; start shooting in Phase 4 | Owner |
| R6 | Scope creep during Phases 4–8 | High | Medium | MVP boundary in §4 is the contract; new requests go to a backlog reviewed at each gate | Lead |
| R7 | Profit figures disputed, eroding trust in the system | Medium | High | Reconcile ten real orders by hand at the Phase 5 gate before declaring it done | Lead + owner |
| R8 | Stock oversell under concurrent checkout | Medium | High | Locking and reservation per `DATABASE-DESIGN.md` §4.1, plus an explicit concurrency test | Lead |
| R9 | Weak SEO results despite the investment | Medium | Medium | Schema, Core Web Vitals and slug-history 301s are CI-gated, not best-effort; content plan from Phase 7 | Lead |
| R10 | Owner unavailable for decisions and UAT | Medium | High | Gates have named decisions and a 24-hour response expectation; blocked work is reported, not worked around | Owner |
| R11 | COD return losses exceed margin | Medium | High | Risk module in MVP; monitor return rate weekly from launch; consider partial advance payment for high-risk orders | Owner |
| R12 | Single VPS failure | Medium | High | Runbook, infrastructure as code, monthly restore rehearsal; managed database when volume justifies | Lead |
| R13 | Bangla deferral turns out to be wrong | Medium | Medium | Schema already carries `*_bn` fields; re-decide at Gate 4 with real visitor data | Owner |

---

## 7. Recommended Priorities

If the budget or timeline must shrink, cut in this order — last listed is cut first.

1. **Order → courier → delivered → honest P&L.** This is the business. Without it, the owner is still running the shop from a notebook and the platform has not earned its cost.
2. **A fast, crawlable, trustworthy PDP and a guest COD checkout.** This is the revenue.
3. **Inventory with real landed costing.** Without it, every profit number is fiction.
4. **Risk screening.** Each prevented fake order saves both courier charges plus the margin; this pays for itself fastest of anything on the list.
5. **SEO module with schema and 301 handling.** Cheap to build now, impossible to retrofit cleanly, and it compounds.
6. **CMS.** Saves developer time every week thereafter.
7. **Promotions and coupons.** Needed, but a flat discount in the admin covers launch.
8. **Analytics depth.** The dashboard matters; cohort analysis can wait for data worth analysing.
9. **Bangla UI, children's profiles, gift finder, campaigns.** Genuine differentiators — but after the store is selling.

---

## 8. Decisions Needed at Gate 1

1. Approve or amend the MVP boundary in §4.
2. Resolve the empty Figma file (three options in `UI-UX-DESIGN-SPEC.md` §1).
3. Confirm the team: who are the developers and the designer, and at what availability? Every estimate here depends on it.
4. Bangla UI at launch, or Phase 2?
5. Confirm the payment gateway and start merchant onboarding this week.
6. Confirm whether Pathao and Steadfast merchant accounts already exist.
7. Answer the open questions in `SYSTEM-ARCHITECTURE.md` §15 — especially whether the business holds its own stock, and who will operate the admin daily.
