# Toy Mart BD — Implementation Plan

**Status:** Active — Phase 3 in progress · **Date:** 2026-11-07 · **Updated:** 2026-11-11
**Scope note:** Phases 3 and 4 are broken down to task level because they start first and the detail is actionable now. Phases 5–10 are broken to feature level; each is expanded to task level at the start of its own phase, when the preceding phase's reality is known. Writing task-level detail for Phase 8 today would be guesswork dressed as a plan.

---

## 0. Conventions

- **Task ID** `P{phase}-{domain}-{n}` e.g. `P4-CAT-03`
- **Branch** `phase-4/catalog-variant-matrix`
- **Size** S ≤ 0.5 day · M 1 day · L 2 days · XL 3+ days (an XL must be split before work starts)
- **Owner** the agent or developer taking the task; one owner per task, one task per branch
- Every task lists its **tests**, and is not done until they pass. See `AGENTS.md` §6.

---

## 1. Repository Layout

**Superseded 2026-11-08 by the owner's "two frontends, two homes" decision (CLAUDE.md rule 25).** The admin SPA lives inside Laravel and is built by Laravel's Vite; only the storefront is a separate app. The npm workspaces root is the repo root.

```
toymartbd/
├── app/{Domain,Http,Support,Providers,Models}/   Laravel 13 at the repo root
│   ├── Domain/{Catalog,Inventory,Cart,Ordering,Payment,Fulfilment,Risk,Finance,
│   │          Customer,Marketing,Content,Seo,Tracking}/   boundaries enforced by deptrac.yaml
│   └── Support/          Money (+ Casts/MoneyCast), PhoneNumber, DhakaDate, Slug, Locale/
├── bootstrap/ · config/ · database/{migrations,seeders,factories}/ · lang/{en,bn}/
├── routes/{api_v1_admin.php,api_v1_storefront.php,api_v1_webhooks.php,web.php,console.php}
├── resources/
│   ├── js/admin/         React 19 admin SPA (app/, components/, features/, lib/) — served at /admin
│   ├── css/admin.css     Tailwind v4 entry, consumes packages/tokens
│   └── views/            admin.blade.php shell, invoice PDFs, mail
├── packages/
│   ├── tokens/           Figma export → tokens.css, Tailwind theme, tokens.json (see its README)
│   └── api-types/        generated from OpenAPI — never hand-edited
├── toymartbd-app/storefront/   Next.js 16 App Router
├── tests/{Arch,Unit,Feature}/  Pest; MySQL-only tests in group "mysql"
├── docker-compose.yml · Makefile   local services and one-command setup
├── docs/{_research,_design}/
└── .github/workflows/
```

One repository, because the API and both frontends share a contract and must change together in one reviewable PR. The storefront stays a separate app because its needs are the opposite of the admin's: read-heavy, SEO-critical, CDN-cached for mid-range Android on 4G, against write-heavy, logged-in, never indexed and tuned for data density.

---

## 2. Phase 3 — Foundation · 18 dev-days

### Setup
| ID | Task | Size | Depends | Tests |
|---|---|---|---|---|
| P3-SET-01 | Monorepo skeleton, workspaces, Composer, EditorConfig, Pint, PHPStan L6, ESLint, Prettier, TS strict configs | M | — | lint and typecheck run clean on an empty project |
| P3-SET-02 | `docker-compose.yml`: MySQL 8.4, Redis 7, Meilisearch, Mailpit; `.env.example`; one-command bootstrap script | M | 01 | `make up` yields a working stack from a clean clone |
| P3-SET-03 | Laravel 13 install, `utf8mb4_0900_ai_ci` enforced, **UTC storage with `APP_DISPLAY_TIMEZONE=Asia/Dhaka`** (never `app.timezone=Asia/Dhaka` — `DATABASE-DESIGN.md` rule 8), Horizon, Telescope (local only) | M | 02 | migration test asserts collation; a Bangla string round-trips |
| P3-SET-04 | Domain directory structure + `deptrac.yaml` encoding the boundary rules | M | 03 | `deptrac` fails on a deliberate `Domain → Http` import |

### Support layer
| ID | Task | Size | Depends | Tests |
|---|---|---|---|---|
| P3-SUP-01 | `Money` value object + Eloquent cast, `DECIMAL(14,2)`, no float arithmetic anywhere | M | P3-SET-04 | add/subtract/percentage/allocation; rounding at 0.005; allocation of 100.00 across 3 lines loses no poisha |
| P3-SUP-02 | `PhoneNumber` value object: normalise to `01XXXXXXXXX`, accept `+880`/`880`/`0` prefixes, validate against live operator prefixes, `masked()` for staff views | M | P3-SET-04 | every valid operator prefix accepted; invalid prefix, wrong length and non-numeric rejected; all input forms normalise identically |
| P3-SUP-03 | `DhakaDate` helper for report bucketing; a lint rule or test forbidding raw `now()` in report queries | S | P3-SET-04 | an order placed 23:30 UTC buckets to the next Dhaka day |
| P3-SUP-04 | Slug generator with Bangla transliteration and uniqueness | S | P3-SET-04 | Bangla name yields a usable ASCII slug; collisions suffix correctly; **one slug serves both locales** |
| P3-I18N-01 | Laravel locale resolution, lang files, explicit-locale API contract (`?locale=` plus `Accept-Language` fallback), locale-aware date/number formatters pinned to Western digits | M | P3-SET-03 | a request resolves the right locale from each source; an unknown locale falls back to `en` without erroring |
| P3-I18N-02 | Next.js locale routing — English at `/`, Bangla at `/bn/`; `hreflang` pair plus `x-default` emitted on every page; persisted toggle; Bangla suggested (never force-redirected) on a first BD visit | L | P3-STO-01, P3-I18N-01 | both locale trees render; `hreflang` is reciprocal on every template; a crawler is never redirected |
| P3-I18N-03 | Lint rule failing any hardcoded user-facing string in `resources/js/admin` and `toymartbd-app/storefront`; i18n key extraction script | S | P3-I18N-02 | a deliberately hardcoded label fails CI |

### Data foundation
| ID | Task | Size | Depends | Tests |
|---|---|---|---|---|
| P3-DAT-01 | `users`, `settings`, `activity_log`, `audit_sensitive` migrations; `audit_sensitive` has no delete grant | M | P3-SET-03 | insert-only enforced |
| P3-DAT-02 | `spatie/laravel-permission`; the 7 launch roles and full permission set seeded | M | 01 | each role's permission set asserted |
| P3-DAT-03 | `divisions`, `districts` (64), `upazilas` with Bangla names and `is_inside_dhaka_metro` flags, seeded from a sourced dataset | L | P3-SET-03 | 8 divisions, 64 districts, upazila counts per district; Bangla names render |
| P3-DAT-04 | Factories and a demo seeder with Bangla product names, variants, images, stock and orders | L | P3-DAT-03 | `migrate:fresh --seed` succeeds and produces a browsable catalog |

### API foundation
| ID | Task | Size | Depends | Tests |
|---|---|---|---|---|
| P3-API-01 | Route groups for admin/storefront/webhooks; base `ApiController`, `JsonResource` and `FormRequest` classes | M | P3-SET-04 | route list matches the contract |
| P3-API-02 | RFC 9457 exception handler with stable machine-readable `type` per error class | M | 01 | validation, auth, authorisation, not-found and server errors each return the right shape and `type` |
| P3-API-03 | `Idempotency-Key` middleware with Redis storage and response replay | M | 01 | replay returns the original response and does not re-execute; concurrent identical requests execute once |
| P3-API-04 | Rate limiters per `SYSTEM-ARCHITECTURE.md` §5 | S | 01 | each limiter returns 429 at its threshold with `Retry-After` |
| P3-API-05 | Sanctum: admin cookie session, storefront server token with limited abilities, customer token | L | P3-DAT-02 | login/logout; CSRF enforced on admin writes; a storefront token cannot reach an admin route |
| P3-API-06 | Policy scaffold + the 403 role matrix test helper | M | 05 | every admin endpoint returns 403 for every role lacking its permission |
| P3-API-07 | OpenAPI 3.1 generation, published at `/api/docs`, committed to the repo; `openapi-typescript` generates `packages/api-types` | M | 01 | CI fails if the committed spec is stale |
| P3-API-08 | `/api/v1/health` reporting database, Redis, queue depth, Meilisearch, last courier sync | S | 01 | degraded dependency surfaces in the response |

### Frontend foundation
| ID | Task | Size | Depends | Tests |
|---|---|---|---|---|
| P3-ADM-01 | Vite + React 19 + TS strict; Tailwind v4 consuming `packages/tokens`; dark mode wired to semantic tokens | M | P2 tokens | build passes; no hardcoded hex in `src/` (lint rule) |
| P3-ADM-02 | Router, TanStack Query with sane defaults, generated API client, auth guard, error boundary, toast host | M | 01, P3-API-07 | 401 redirects to login; a failed query surfaces a retryable error |
| P3-ADM-03 | `AppShell`: sidebar, topbar, theme toggle, responsive drawer | M | 01 | keyboard navigable; renders at 768px |
| P3-ADM-04 | Login screen + one real CRUD screen (brands) proving table, form, validation and mutation end to end | L | 02, 03 | Vitest on the form schema; Playwright login → create → edit → delete |
| P3-STO-01 | Next.js 16 App Router, Tailwind from `packages/tokens`, fonts self-hosted and subset | M | P2 tokens | build passes; no CLS from font loading |
| P3-STO-02 | Server-only API client with the storefront token; typed from `packages/api-types`; `no-store` vs. tagged-cache helpers | M | P3-API-07 | no credential appears in any client bundle (asserted by a bundle grep test) |
| P3-STO-03 | `/api/revalidate` route handler with shared-secret verification; Laravel listener dispatching tag revalidation | M | 02 | a product save revalidates only its own tags; an unsigned request is rejected |
| P3-STO-04 | One real catalog page rendered from the API with ISR, proving the stack | M | 02, 03 | Playwright asserts server-rendered HTML contains the product name with JS disabled |

### Pipeline
| ID | Task | Size | Depends | Tests |
|---|---|---|---|---|
| P3-CI-01 | PR workflow: Pint, PHPStan, deptrac, Pest, ESLint, tsc, Vitest, Playwright smoke | M | all above | a deliberately broken PR fails |
| P3-CI-02 | Staging deploy on merge to `main`: zero-downtime release swap, migrations, graceful Horizon restart | L | 01 | deploy and rollback both rehearsed |
| P3-CI-03 | Automated encrypted backup to off-host storage **and a verified restore onto staging** | M | 02 | restore produces a working application — this is the acceptance criterion, not the backup itself |
| P3-CI-04 | Sentry on all three apps, structured JSON logging, `X-Request-Id` propagated into queued jobs | M | 01 | a thrown error reaches Sentry with the request id attached |

### Progress (updated 2026-11-11, batch 3)

**Done, with passing tests** (394 PHP on SQLite, 395 on MySQL; 21 admin Vitest + 2 admin Playwright; 45 storefront unit + 16 storefront Playwright; PHPStan L6, Pint, deptrac clean):
- Batch 1 (PR #2): P3-SET-01…04 · P3-SUP-01…04 · P3-I18N-01 · P3-DAT-01…02 · P3-API-01, 02, 04, 08 · P3-CI-01.
- Batch 2 (PR #3): P3-DAT-03 · P3-API-03, 05, 06, 07 · P3-ADM-01…03 · P3-I18N-03 (as a Vitest guard).
- Batch 3:
  - **P3-ADM-04** — brands, the first real CRUD: `app/Domain/Catalog` (Actions own the transactions; `DELETE` archives, never deletes), admin endpoints with filter/sort allowlists and cursor pagination, storefront read endpoints behind the `storefront:read` token, admin list + form screens with client validation from **Zod generated off the OpenAPI spec** (`packages/api-types/scripts/zod.mjs`, rule 27), and a Playwright run on the real app: login → create → edit → archive, in English and Bangla.
  - **P3-STO-01…04, P3-I18N-02** — Next.js 16 storefront: tokens + self-hosted fonts, English at `/` and Bangla at `/bn/` with no redirect (verified for Googlebot and Bangla browsers), hreflang both ways + `x-default`, server-only API client, signed `/api/revalidate` per `docs/api/revalidation.md`, ISR `/brands` and `/brands/[slug]`, and a test that no token or secret reaches a client bundle.
  - **P3-CI-04** — request ids (propagated into queued jobs and problem bodies), JSON logs, phone/credential redaction on every log channel and in Sentry events, Sentry wired but off until `SENTRY_LARAVEL_DSN` is set.
  - **P3-CI-03 (local part)** — `toymart:backup` (encrypted, manifest with row counts) and `toymart:backup:restore-rehearsal` (restores into a scratch DB and compares; passed 32/32 locally).
- Verified on real services, not only in tests: storefront rendering the real API's brands in both locales, and an admin rename revalidating the cached storefront page through the signed hook.

**Still open in Phase 3:**
- **P3-CI-02 / P3-CI-03 acceptance** — staging deploy and a restore onto staging need a staging host and an off-host bucket (owner infra). See `docs/ops/backup-and-restore.md`.
- ~~P3-DAT-04~~ — done in Phase 4 batch 1 (`DemoCatalogSeeder`).
- Gate 3 (storefront stack decision) can now be taken against a working Next.js skeleton.

**Owner decisions needed** (in addition to Dhaka zones and price display from batch 2):
- **Bangla font budget.** Anek Bangla's Bengali file is ~156 KB and Baloo Da 2's ~90 KB, over the 40 KB-per-file target in `UI-UX-DESIGN-SPEC.md` §3.2. They load only on pages with Bangla text. Options: fewer weights, a separate Bangla budget, or a lighter face.
- **Fredoka has no 800 weight** (max 700), so Latin `display-1`, `display-2` and `price-lg` render at 700 while Bangla gets 800. Change the token weight to 700, or choose another display face.

**Follow-ups:**
- Storefront 404 pages render their localized message only with JavaScript (Next 16.4 limitation; status 404 + `noindex` are correct without it).
- Sentry SDKs for the storefront and admin; the storefront should forward `X-Request-Id`; `request_id` in the OpenAPI problem schema; `last_backup_at` on `/health`; backup heartbeat; binlog shipping for the 1-hour RPO.
- Slug history / 301s for renamed brands (`TODO(P4-SEO)`; `BrandChanged` carries the old slug).
- Earlier: ESLint (TS 7 support), trusted proxies, translated problem titles, native-speaker review of all Bangla strings, `users.locale`.

**Phase 3 exit:** clone → one command → working stack with Bangla seed data · login with roles · OpenAPI published and types generated · CI green · staging deploying · a restore performed successfully. Then **Gate 3** — the storefront stack decision.

---

## 3. Phase 4 — Core E-commerce · 52 dev-days

### Progress (updated 2026-11-11, Phase 4 batch 1 — PR #5)

**Done, with passing tests** (597 PHP on SQLite, 598 on MySQL; 39 admin Vitest + 3 admin Playwright; 101 storefront unit + 39 storefront Playwright; PHPStan L6, Pint, deptrac clean):
- **P4-CAT-01/02** — all catalog tables, models, enums, factories, policies (categories via `kalnoy/nestedset`).
- **P4-CAT-03** — `CreateProduct`/`UpdateProduct` generate the variant matrix; removing a value archives its variants, re-adding reactivates them. SKU scheme in `app/Domain/Catalog/Services/VariantSkus.php`.
- **P4-CAT-08** — category tree admin (keyboard/button reorder, indent/outdent; drag not built). **P4-CAT-09** — attributes and values admin (brands were done in P3).
- **P4-CAT-11** — product duplicate (API only; SEO copy waits for P7 tables).
- Admin product API (`/api/v1/admin/products`): the form screens (P4-CAT-04/05) are the next batch.
- **P4-STO-01** — header, mega menu from `/storefront/categories/tree`, bottom tab bar, footer. **P4-STO-12** — styled 404 (now server-rendered for unknown URLs), error, maintenance, About template.
- **P3-DAT-04** — `DemoCatalogSeeder`: 13 products with Bangla names, 3 with variants (local/testing only).
- Verified on the real app: product and category APIs, the mega menu rendering seeded categories in both locales, and a category rename revalidating the storefront.

**Open:** image upload (P4-CAT-07) — image rows exist with `path` null; category image/banner upload waits for it. The contact details in `toymartbd-app/storefront/lib/site-config.ts` are placeholders (the hotline differs between the content spec and the footer design: 09600- vs 09610-123456).

### Progress — Phase 4 batch 2 (PR #6, 2026-11-12)

**Done, with passing tests** (688 PHP on SQLite, 689 on MySQL; 75 admin Vitest + 4 admin Playwright; 135 storefront unit + 52 storefront Playwright; PHPStan L6, Pint, deptrac clean):
- **P4-CAT-04/05** — admin product list and tabbed form with `VariantMatrixEditor`.
- **P4-CAT-06/07** — media pipeline (content-sniffed uploads, EXIF stripped, WebP/AVIF renditions after commit) and `ImageUploader`; publish blocked until every image has EN and BN alt text. Category/brand image upload not yet wired.
- **P4-CAT-13 / P4-STO-03** — storefront catalog API with disjunctive facets; category and brand listings that work without JS. "In stock" filter waits for inventory.
- **Figma fidelity** (owner requirement: match Figma 100%) — admin shell, primitives, sign-in, categories split view; storefront chrome, 404/500/maintenance/About. Every Figma text/effect/paint style exported; missing radii, rim, shadows, deco/ink colours added in Figma and bound with zero visual change.
- **Demo catalogue** = the 16 PART C products with the Figma product photos, uploaded through the real pipeline (`DEMO_SEED_IMAGES`).

**Owner decisions open:** strip C2PA "AI-generated" metadata on upload (it embeds an SVG, so such PNGs are refused today); card price = lowest variant vs. headline variant; Bengali vs. Western digits in Bangla product names; `ink-*` colours fail AA in Dark and `ink-mint` on `tile-mint` is 4.44:1 (pre-existing in Figma); spacing tokens for the frequent raw 6/10/14 values.

### Progress — Phase 4 batch 3 (PR #7, 2026-11-12)

**Done, with passing tests** (775 PHP on SQLite; 78 admin Vitest; 155 storefront unit; PHPStan L6, Pint, deptrac clean):
- **P4-CRT-05 (inventory core)** — `stock_locations`, `stock_levels`, append-only `stock_movements`; `ReserveStock`/`ReleaseStock` lock rows `FOR UPDATE` in ascending `variant_id`; opening stock on product create; `StockAvailability` (`available = on_hand - reserved`).
- **P4-CRT-03 / P4-STO-06** — one `DeliveryChargeResolver` (zones, free-shipping threshold, weight surcharge) behind `/storefront/delivery/*`; PDP `DeliveryEstimator` and a `/delivery-charge` page. `courier_providers` table seeded unverified.
- **P4-CAT-14** — Meilisearch via Scout with a database fallback engine, queued sync on save, zero-gap rebuild command, Bangla/Banglish synonyms, spelling suggestions, index-lag check; storefront search + suggestions API.
- **P4-STO-05** — product detail page: gallery, variant selector with disabled out-of-stock options, price block, stock state, info tabs, trust strip, sticky add-to-cart bar, related products.

**Open:** best sellers rank by recency until orders exist.

### Progress — Phase 4 batch 4 (PR #8, 2026-11-12)

**Done, with passing tests** (1208 PHP on SQLite; 91 admin Vitest; 177 storefront unit + 77 storefront Playwright; PHPStan L6, Pint, deptrac clean):
- **P4-CRT-01/02/04** — carts by httpOnly token (`X-Cart-Token`), line add/update/remove with stock checks, `MergeGuestCart`; `PricingPipeline` (base → promotions → coupon → shipping via `DeliveryChargeResolver` → total, breakdown must sum); coupons with window, minimum, total and per-phone limits, scope and exclusions; admin coupons API. `first_order_only` reads real orders (`Ordering\Queries\PhoneOrderHistory`).
- **P4-ORD-01/03/09/10, P4-PAY-01** — customers, addresses, orders (three status columns, `TMB-YY-NNNNN` numbers), items with `cost_price_snapshot`, events, payments/refunds; guarded state machines for all three statuses; COD driver behind `PaymentGatewayRegistry`; `CancelOrder` releases stock; customer aggregates + rebuild command; admin orders read API with PII masking. PlaceOrder outline in `docs/api/orders.md`.
- **Admin inventory** — `AdjustStock` (ordered locks, never below reserved), stock overview, movements ledger with reconciliation, adjustment dialog (Figma 213:6955 / 213:7516, Theme B).
- **P4-STO-04 / P4-STO-02 / P4-STO-07 (part)** — search results with suggestions dropdown and zero-results state, home page from live data, `/toys` all-toys listing, recently-viewed rail.
- **Figma fidelity** — admin product add/edit rebuilt as Figma's one-page layout (Theme B `241:37883`, 768 `230:22079`).

**Batch 5:** see below.

### Progress — Phase 4 batch 5 (2026-11-12)

**Done, with passing tests** (1303 PHP on SQLite; 114 admin Vitest; 200 storefront unit; PHPStan L6, Pint, deptrac clean):
- **P4-ORD-02** — `PlaceOrder`: re-price (409 `price-changed` on a mismatch with `expected_total`), all-or-nothing reservation, find-or-create customer by E.164, cost snapshot, COD payment, coupon usage, `OrderPlaced`; retries up to 3× on a MySQL deadlock. Launch risk rule: first order from an unknown phone above `RISK_FIRST_ORDER_HOLD_THRESHOLD` (placeholder ৳3,000) → `on_hold`. Contract: `docs/api/checkout.md`.
- **Inventory fix** — `StockLevelLocks::lock()` ran `INSERT IGNORE` on existing rows, taking shared locks that deadlocked 6 of 10 concurrent checkouts on MySQL 8.4. Now only missing rows are inserted. `CheckoutConcurrencyTest` (group `mysql`) needs a passing CI run to confirm.
- **P4-STO-08/09/10** — cart page, no-JS add to cart, single-page checkout (division → district → upazila, landmark, server-generated Idempotency-Key per render), confirmation (placing cart's token only), tracking (number + last 4 digits, POST, generic 404).
- **P4-ORD-04/05** — admin order board (status tabs, URL filters, cursor paging, bulk confirm with per-order results) and detail (three-track stepper, risk card + "provider not connected" courier panel, masked phone, notes/activity, confirm/hold/release/cancel with typed order number); `order_notes`.
- **Product form gaps** — `seo_meta` + SEO panel with server checklist, `slug_history` + slug-change acknowledgement, stock by branch, sales channels, BSTI certificate, VAT, server-computed margin (`catalog.view_cost` only), recommendation thumbnails.

**API gaps found:** cart lines carry no image/attributes (storefront fetches each product); `StorefrontOrder` has no ETA/courier (Phase 6); no `StorefrontOrder` OpenAPI schema (type mirrored by hand); `recommendation_source` not yet stamped from cart lines; rate limits keyed by IP see the Next server's IP (needs trusted forwarded IP).

**Owner decisions open (batch 5):** first-order hold threshold; VAT wording (Figma says toys are VAT-exempt, research says standard VAT probably applies); admin order board defaults to all dates rather than Figma's "Last 7 days"; extra On hold / Processing tabs.

**Owner decisions open:** coupon after a non-stackable promotion (applies today); customer aggregates (orders count excludes cancelled, total spent = delivered only); partial COD collection (refused today); `cost_price_snapshot` precision 14,4 (§3.4) vs rule 6; Theme B primary colour in the product-edit frame (coral in Figma, indigo in the build); `throttle:search-suggest` is keyed by IP but all shoppers arrive via the Next server.

### Progress — Media Library + Figma fixes (2026-11-12)

Pulled forward from Phase 7 (T-02) at the owner's request; design approved 2026-11-12 with **no video in v1** and a **10 MB** upload limit.

**Done, with passing tests** (1401 PHP; 153 admin Vitest; 207 storefront unit; 10 admin + 84 storefront Playwright; PHPStan, Pint, deptrac clean):
- **Media Library** — `media` + `media_folders` (code in `app/Domain/Catalog/Media`, so Catalog can reference it), expand-only `product_images.media_id` with an idempotent backfill (`toymart:media:backfill`), upload/replace/delete (409 `media-in-use`), bulk move/delete/zip, usage resolver, stable `/m/{id}/{name}` URL; replace keeps the media id, never the bytes path (supersedes A25 rule 3 wording). Admin screen `/store/media` and `MediaPickerModal` with "Choose from library" in the product images panel. Contract: `docs/api/media-library.md`.
- **Product form** — rich-text description (TipTap; sanitised server-side by `ProductDescription` with symfony/html-sanitizer, again on the storefront); Variants section always present, matrix decides the type on create; section tabs sticky with scroll margin (the Inventory tab looked inert). Nested-form bug fixed: confirming an image delete also saved the product.
- **Inventory page** — matched to Theme B `241:39494`: branch cards, Overstock tab, Other branches column, contextual row actions, low-stock nav badge, 3-branch demo seed through opening-balance movements.

**Owner decisions (2026-11-12):** the `Aa` (clear formatting) button is removed from the rich-text toolbar; a saved simple product stays unable to become variable (API refuses the type change). **Still open:** overstock = `available > reorder_point + 2 × reorder_quantity` (needs reorder_quantity > 0); media delete/bulk download limited to super_admin + manager; storage quota unset.

**Disabled pending later features:** Transfer stock / Transfer N, Create PO, product/QR embed and AI write in the description toolbar.

### Progress — Phase 4 batch 6 (2026-11-12)

**Done, with passing tests** (1546 PHP; 171 admin Vitest; 240 storefront unit; PHPStan, Pint, deptrac, i18n lint clean):
- **P4-STO-11** — customer account: phone + SMS OTP (HMAC-stored code, 5-min expiry, 60 s cooldown, 5/hour, 5 wrong guesses burn it), `customer` Sanctum guard, guest cart merged on verify, account home, orders + detail (another customer's order is a 404), addresses, wishlist (`wishlist_items`), profile. Storefront keeps the token in the httpOnly `tm_account` cookie; every form works without JS.
- **P4-NOT-01/02/03, P4-I18N-03** — `Notification` domain: `SmsGateway`/`WhatsAppGateway` contracts, log/null drivers (SMS provider driver is an unverified skeleton that refuses to send), `notification_logs` with segments and cost, dead-letter after 3 tries with admin retry, preferences (security category cannot be opted out), DB templates EN + BN with server-measured segments (UCS-2 70/67, GSM-7 160/153), order emails in both locales, admin Automations + message log.
- **P4-ORD-06/07/08** — phone/manual orders through `PlaceOrder` (`source=phone`), `EditOrder` up to `processing` (reservation deltas in one ascending lock pass, cost snapshots kept, before/after audit without PII), bulk mark packed, invoice PDF via mPDF + Hind Siliguri (dompdf cannot shape Bangla). Courier booking disabled until Phase 6.
- **P4-CAT-10** — CSV/XLSX import (openspout) previewed row by row through the real FormRequests + actions inside rolled-back transactions, then committed; row/column error report; upsert by SKU; export with the list filters; BOM + formula guard.
- **P4-CAT-12** — reviews with verified purchase derived from a delivered `order_item_id` (Catalog → `DeliveredPurchases` contract implemented by Ordering), moderation queue, aggregates from approved reviews only, PDP reviews section, `reviews.moderate` permission.
- **P4-I18N-01/02** — `scripts/i18n/lint.mjs` (hardcoded JSX text and literal a11y props) and `check-keys.mjs` (en/bn key parity, placeholders, no Bengali digits) in CI; Bangla layout audit Playwright spec (14 screens + checkout flow, both locales, 390/1440); Phase 4 golden-path spec.
- Admin product form: the "Also list in" multi-category list is removed (owner, 2026-11-12); the Category select is the product's one category.

**Open / owner decisions:** SMS provider and WhatsApp Business account (all rates placeholders); TrustProxies so per-IP OTP limits mean something; order edit re-prices at today's price; packed orders not editable; risk not re-scored after edit; `reviews.moderate` for content_editor; export needs only `catalog.view`; import creates simple products only; reviewer display name "First L."; unlisted account-order filters return 422 not 400; account pages not yet in the Bangla layout spec; wishlist hearts on cards/PDP not wired; Figma Bangla-frame comparison still owed. **Blocked:** P4-PAY-02/03/04 wait on the payment-gateway decision (Gate 1 decision 5).

### Progress — Phase 4 gap closure (2026-11-13, with Phase 5 batch 1)

**Done, with passing tests:**
- **P4-PAY-02/03/04 (SSLCommerz)** — built against the published v4 API and the official PHP library only (research §1.7 recommends it; the owner has **not** confirmed the gateway or opened a merchant account, Gate 1 decision 5). Driver: session, validation, query, refund, refund status; hand-authored fixtures, no network in tests. IPN: md5 `verify_sign` first, `webhook_receipts` idempotency, re-query for the authoritative amount/currency/status, mismatch → flag for review + order on hold, never paid. Expiry sweeper (every 5 min) re-queries first, then expires and cancels, releasing stock; retry never re-reserves. `payment_gateways` row seeded **inactive and unverified** — production offers no online payment until `toymart:payments:gateway sslcommerz --activate --verify --live`. Storefront online option (COD stays default), payment status/retry page; admin "Re-check with gateway".
- **Manual fulfilment** — `MarkOrderShipped` (consumes the reservation once: `sale_out` movements, ascending locks) and `MarkOrderDelivered` (COD payment paid, `OrderDelivered` → Finance recognises revenue/COGS; no cash until remittance), single and bulk, admin buttons. No `courier_charge` until Phase 6. Ordering now binds `OpenOrderReservations`, so nightly reconcile checks reserved vs open orders.
- Wishlist hearts on cards/PDP (no-JS form; PDP stays cached, filled state from a private uncached endpoint); account pages in the Bangla layout audit; trusted proxy (`TRUSTED_PROXIES`, default none, wildcards refused) with the shopper IP forwarded by the Next server — OTP per-IP cap 30/h; rule 16 400s on account orders and product reviews; `recommendation_source` carried cart line → order item (no rails send it yet); cart lines carry image + variant attributes; generated `StorefrontOrder` type.

**Open:** Gate 4 owner UAT on staging (needs owner infrastructure); production must set `TRUSTED_PROXIES`; payment window 30 min / 5 attempts are placeholders; no gateway-fee or settlement ledger rows for online payments; no poller for SSLCommerz refund status; manual failed-delivery/return-to-shop not built; guest wishlist (spec allows without sign-in; built sign-in-only); Figma comparison of the batch-6 admin screens and Bangla account screens still owed.

### Catalog (CAT)
| ID | Task | Size | Tests |
|---|---|---|---|
| P4-CAT-01 | Migrations: products, variants, attributes, attribute values, variant values, categories (nested set), brands, images, relations | L | tree integrity after move/delete; unique SKU and barcode |
| P4-CAT-02 | Models, relations, scopes, enums, policies | L | relation and scope coverage |
| P4-CAT-03 | `CreateProduct` / `UpdateProduct` actions generating the variant matrix from selected attribute values | L | simple product gets exactly one default variant; a 3×4 attribute matrix creates 12 variants; removing a value archives rather than deletes its variants |
| P4-CAT-04 | Admin product CRUD: tabbed form (General, Pricing, Variants, Inventory, Images, SEO, Related) | XL→split | Vitest on schemas; Playwright create-with-variants |
| P4-CAT-05 | `VariantMatrixEditor`: per-row price, cost, SKU, barcode, stock, status | L | bulk edit; validation per row |
| P4-CAT-06 | `ImageUploader`: drag-drop, reorder, per-image alt text (both languages), crop, variant assignment | L | reorder persists; alt text required before publish |
| P4-CAT-07 | Media pipeline: validate real MIME, re-encode through Intervention (strips EXIF), generate AVIF/WebP at defined widths, CDN URLs | L | a renamed `.php` upload is rejected; EXIF stripped; variants generated |
| P4-CAT-08 | Category tree admin: drag reorder, nesting, image, menu visibility | L | tree operations; menu cache invalidation |
| P4-CAT-09 | Brands, attributes and attribute values admin | M | CRUD + role tests |
| P4-CAT-10 | Bulk CSV/Excel import and export with a row-level error report | L | 1,000-row import; malformed rows reported, not silently skipped; Bangla names survive round-trip |
| P4-CAT-11 | Product duplication including variants, images and SEO | S | duplicate has new SKUs and slug |
| P4-CAT-12 | Review submission and moderation; `is_verified_purchase` derived from `order_item_id`; rating aggregates maintained | M | unverified review cannot claim verified status; aggregates recompute on approve/reject |
| P4-CAT-13 | Storefront catalog API: listings, filters (**age range, price, brand, category, gender, rating, availability**), sorting, cursor pagination, facet counts | L | unlisted filter returns 400; facet counts match results; age filter boundaries inclusive |
| P4-CAT-14 | Meilisearch: index schema, queued sync on save, nightly full reindex, Bangla + Banglish synonyms, typo tolerance, facets | L | a product is searchable within 5s of save; "gari"/"গাড়ি"/"car" return the same product; index-lag alert fires |

### Storefront (STO)
| ID | Task | Size | Tests |
|---|---|---|---|
| P4-STO-01 | Layout: header, mega menu, mobile bottom tab bar, footer, search bar | L | keyboard navigation of the mega menu; 320px reflow |
| P4-STO-02 | Home page from CMS blocks (static fallback until Phase 7) | L | Lighthouse budget; LCP image has `priority` |
| P4-STO-03 | Category and brand listing with filter sidebar / mobile bottom sheet, chips, sort | XL→split | filter state is URL-driven and shareable; back button restores it |
| P4-STO-04 | Search results and zero-results with suggestions | M | zero-results shows corrections and best sellers |
| P4-STO-05 | PDP: gallery with zoom, variant selector, price block, stock state, age indicator, trust strip, sticky add-to-cart, tabs | XL→split | CLS ≤ 0.05; out-of-stock variant disabled with a reason; price never reflows after load |
| P4-STO-06 | **`DeliveryEstimator` on the PDP** — district select returning the real charge and ETA before the cart | M | the quote equals the charge later applied at checkout (same code path) |
| P4-STO-07 | Related products, frequently bought together, recently viewed | M | no N+1; respects stock |
| P4-STO-08 | Cart page, line editing, coupon field, summary, empty state | L | quantity beyond stock blocked with a message |
| P4-STO-09 | Checkout: single page, three accordion sections, cascading address selects, COD default, sticky total, idempotent submit | XL→split | guest checkout; double-submit creates one order; price mismatch blocks with an explicit message |
| P4-STO-10 | Order confirmation, payment failure/retry, public tracking by number + phone | M | tracking requires both; wrong phone returns 404, not a hint |
| P4-STO-11 | Account: phone+OTP auth, dashboard, order history and detail, addresses, wishlist, profile | XL→split | OTP rate limited; a customer cannot read another's order |
| P4-STO-12 | 404, 500, maintenance, static page template | S | styled, with a route back into the catalog |

### Cart & Checkout engine (CRT)
| ID | Task | Size | Tests |
|---|---|---|---|
| P4-CRT-01 | `carts`/`cart_items` migrations; cart resolution by httpOnly token; merge on login | M | guest cart merges into the account cart without duplicating lines |
| P4-CRT-02 | Pricing pipeline: base → promotion (priority, stacking) → coupon → shipping → total, returning an itemised breakdown | L | stacking order; non-stackable stops the chain; breakdown sums to the total |
| P4-CRT-03 | Delivery-charge resolver per `DATABASE-DESIGN.md` §5.1 — **one implementation, used by both the estimator and checkout** | M | every zone; free-shipping threshold; weight surcharge; estimator and checkout agree |
| P4-CRT-04 | Coupon validation: window, minimum, total and per-customer limits keyed on phone, scope, exclusions | M | per-customer limit holds across guest checkouts on the same phone |
| P4-CRT-05 | Stock availability check and reservation helper | M | `available = on_hand - reserved`; reservation expiry releases |

### Orders (ORD)
| ID | Task | Size | Tests |
|---|---|---|---|
| P4-ORD-01 | Migrations: orders (3 status columns), order_items with `cost_price_snapshot`, order_events, order_financials | L | status enums; indexes present |
| P4-ORD-02 | `PlaceOrder` action — the full transaction in `DATABASE-DESIGN.md` §4.1 | XL→split | **concurrency test: 10 parallel checkouts against 5 units sell exactly 5** · cost snapshot written · reservation taken · events recorded |
| P4-ORD-03 | Order state machine with guarded transitions and emitted events | L | every invalid transition rejected; each valid one emits its event |
| P4-ORD-04 | Order board: status tabs with counts, filters, cursor pagination, bulk select | L | filters are URL-driven; counts match |
| P4-ORD-05 | Order detail: items, customer, addresses, payments, shipment, timeline, internal notes, risk panel | XL→split | role-based PII masking |
| P4-ORD-06 | Manual/phone order creation with product search, customer lookup by phone, address entry | L | creates an identical order shape to a web order with `source=phone` |
| P4-ORD-07 | Order edit after placement with stock and total recalculation and a full audit trail | L | editing adjusts reservations correctly; audit records before/after |
| P4-ORD-08 | Bulk processing: confirm, print invoices, mark packed, book courier | M | partial failure reports per-order results, never a silent all-or-nothing |
| P4-ORD-09 | Cancel and release-reservation flow with reason capture | M | coupon usage reversed; risk counters updated |
| P4-ORD-10 | Customer aggregate projection (orders count, total spent, AOV, first/last order) | M | rebuildable from orders; matches a recomputation |

### Payments & notifications (PAY / NOT)
| ID | Task | Size | Tests |
|---|---|---|---|
| P4-PAY-01 | `PaymentGateway` contract, `payments`/`refunds` migrations, COD driver | M | COD flows through the same status transitions as online |
| P4-PAY-02 | Online gateway driver for the recommended provider: initiate, verify, query | L | recorded-fixture integration tests for success, failure, timeout, malformed |
| P4-PAY-03 | Webhook endpoint: **verify signature → re-query provider for authoritative amount and status → then write**; `webhook_receipts` idempotency | L | invalid signature rejected before parsing; replay is a no-op; an amount mismatch does not mark the order paid |
| P4-PAY-04 | Payment failure, retry and expiry handling; reservation release on expiry | M | abandoned online payment releases stock |
| P4-NOT-01 | SMS driver + templates; `notification_logs` with per-message cost | M | failure dead-letters visibly; cost recorded |
| P4-NOT-02 | Email driver + templates (order placed, confirmed, shipped, delivered) | M | renders with Bangla names |
| P4-NOT-03 | Notification preferences and admin template editor | M | opt-out respected |
| P4-I18N-01 | Bangla + English strings wired for every MVP storefront surface from `docs/_design/05-content-and-assets-spec.md`; no hardcoded label survives the lint rule | L | every key resolves in both locales; a missing key fails CI rather than rendering the key |
| P4-I18N-02 | Bangla layout pass across every MVP screen at 390px and 1440px — string overflow, button widths, nav wrapping, `.lang-bn` line-height | L | no clipped or overflowing Bangla label on any screen; Playwright screenshots both locales |
| P4-I18N-03 | Bangla notification templates for SMS, email and WhatsApp, with per-template segment counts recorded so cost is visible | M | Bangla SMS segment count asserted (70 chars/segment, not 160); WhatsApp preferred for order updates, SMS for OTP |

**Phase 4 exit:** a real product can be added and bought COD on a real phone, the SMS arrives, stock reserves and releases correctly, both invariants hold, and the Playwright golden path passes. Then **Gate 4 — owner UAT**.

---

## 4. Phases 5–10 — Feature-level Plan

Expanded to task level at each phase start. Each feature below carries its own tests; the phase gates are in `DEVELOPMENT-ROADMAP.md`.

### Progress — Phase 5 batch 1 (2026-11-13)

**Done, with passing tests** (1914 PHP; 220 admin Vitest; 260 storefront unit; PHPStan, Pint, deptrac, i18n lint clean):
- **Purchasing** — suppliers, POs (draft → ordered → partially_received → received / cancelled / **closed_short**), receipts with landed costs (freight, customs, other) split by value/quantity/weight exact to the poisha, server quote endpoint, weighted-average cost per location on receipt (`ReceivePurchasedStock`), supplier payables and payments (`supplier_payment`, cash, not revenue). Third-party freight/customs → a **capitalised** expense (cash-out only, never a P&L line, since it is already in COGS via landed cost).
- **Inventory operations** — adjustment approval above a threshold (placeholder 20 units / ৳5,000; no self-approval), write-offs (damage, loss, theft, expiry → `inventory_write_off`), branch transfers with two-location ordered locks, nightly reconciliation (03:30 Dhaka, alerts, never auto-corrects), low/out-of-stock alerts and a 09:00 Dhaka digest.
- **Finance ledger** — `Ledger` contract, append-only `financial_transactions` idempotent on (source, type), revenue on delivery (not cash), manual "Record COD received" (gross amount; courier fee separate), receivables with aging, `order_financials` projection + `toymart:finance:verify` (01:30 Dhaka), expenses with categories, receipts, approval (no self-approval) and recurring generation, `daily_financial_summaries` rollup (01:00 Dhaka).
- **Reports** — P&L day/week/month/year with expense breakdown, profitability by product/category/recommendation source, inventory valuation (live and as-at), CSV exports.
- **Returns & refunds** — returns (request → receive/inspect → refund), resellable restock / damaged write-off at snapshot cost, server-computed refunds capped at captured, approval above ৳2,000, idempotent pay, ledger reversal (recognised on approval, cash on payment), manual COD refund methods, online refund through the gateway contract.

**Owner decisions:** report weeks Saturday–Friday; refund approval threshold ৳2,000; close POs short allowed; third-party freight/customs recorded as a capitalised expense; COD received recorded gross (2026-11-12/13).

**Open:** return window 7 vs 10 days (legal); stock-adjustment approval limits; self-approval for managers; gift/sample as marketing expense; capitalised freight expenses pending vs auto-approved; supplier VAT/AIT (0 today); supplier payment reversal; `stock_levels.incoming` for open POs; exchanges/store credit/open-box not built; customer-initiated returns; P&L PDF export; transfers have no in-transit state; staff mail English-only; who may mark shipped/delivered (orders.update + shipments.book today); the hand reconciliation of ten real orders required by the Phase 5 gate. Figma: most new screens are not drawn and were built from existing components; no screenshot comparison was possible (chrome-devtools not connected).

### Progress — sign-off preparation (2026-11-13)

- **Figma fidelity pass** (Playwright screenshots, since chrome-devtools was unavailable) on every screen built in batch 6 and Phase 5 batch 1, admin at 1440/768 and storefront at 1440/390 in both locales: orders board (rebuilt below 1024 px to the 768 frame), order detail header, message log, P&L, expenses, purchase orders + receive card, ledger/reports, returns, account pages, order timeline, wishlist hearts (with ring-burst), PDP reviews, checkout payment card. Theme B node ids recorded in `docs/_design/06-admin-feature-checklist.md` §6. Shared fix: default `w-full` / `text-start` on inputs, selects and table headers are now zero-specificity, so a screen's own width/alignment wins (it was hiding product names on the receive card). Remaining differences need backend or owner input and are listed per screen in the PR.
- **Horizon** had no `config/horizon.php`, so only the `default` queue would have run in staging/production (notifications, renditions, search sync, write-off ledger entries would have sat in Redis). Fixed with a supervisor per queue and a test that fails when any queue used in code lacks one.
- **Gate 4 kit:** `docs/ops/STAGING.md`, `docs/ops/UAT-GATE-4.md` (phone + admin script, pass/fail boxes, checked against the real screens), `scripts/staging/smoke.sh`. **The UAT itself is still owed** — it needs a staging host and the owner.
- **Phase 5 profit check kit:** `toymart:finance:order-breakdown` (order-side recomputation from source tables vs the ledger, CSV/XLSX, read-only, no PII), `docs/ops/PROFIT-RECONCILIATION.md` and a blank template. Ten-order rehearsal through the real checkout/admin flows agrees to the poisha. **The hand check of ten real orders is still owed.**

**Open:** SSLCommerz gateway fee is not booked to the ledger (online orders' profit overstated by the fee — not relevant while COD-only); "Owed back to a customer" (refunds approved but unpaid) has no endpoint; scheduled tasks from SYSTEM-ARCHITECTURE §7 not yet scheduled (search lag check, nightly reindex, sitemap, cart cleanup); Figma has no Bangla storefront frames; card heart 28/32 (Figma) vs 36/40 (spec); money formatting drops trailing zeros app-wide.

**Phase 5 — Inventory & Operations (35d).** Stock levels and append-only ledger with locking · nightly reconciliation job with alerting · suppliers · purchase orders with partial receipt and landed-cost apportionment · weighted-average costing on receipt · stock adjustments with approval · damage and loss · low-stock alerts and reorder points · inventory valuation · stock-overview screens · financial ledger with the revenue/cash separation · expenses and categories · `order_financials` projection · product-, category- and order-wise profitability · P&L daily/weekly/monthly/yearly · supplier payments · returns and refunds with approval threshold, restocking, ledger reversal and gateway refund.

### Progress — Phase 8 batch 1 + Phase 7 follow-ups (2026-11-13)

**Done, with passing tests** (3304 PHP, 8 skipped; 379 admin Vitest; 426 storefront unit; storefront Playwright 130; PHPStan, Pint, deptrac, typecheck, i18n lint clean):
- **Reporting domain** (`app/Domain/Reporting`, own deptrac layer, read-only) — `Support\DateRange` on top of `Finance\Reports\ReportPeriod` (Dhaka days, Sat–Fri weeks, presets, previous-period comparison); every ledger figure reuses `ProfitAndLoss` / `Profitability` / `LedgerTotals`.
- **Dashboard** (B · 01 `241:29848`, 1024, 768) — `GET /admin/dashboard`: KPIs with deltas + sparklines (orders placed and AOV labelled *placed*; recognised revenue, gross/net profit, cash collected from the ledger; new vs returning; low stock), live status strip, placed-vs-recognised trend, category donut, best sellers, needs-attention queues, PII-free activity feed. Blocks hidden per permission come back `null`, never zero. Seeded month equals the ledger to the poisha. Index `orders(placed_at, status)`.
- **Customers** (B · 17 `241:40845`, B · 18 `241:41374`) — list with search (name / phone digits / order number), allowlisted filters and sorts, segment tiles; profile with masked PII without `customers.view_pii`, order history, LTV/AOV/delivery rate, risk kept separate, append-only `customer_notes`. **Customer analytics** at `/analytics/customers`: segments, LTV distribution, repeat rate, Dhaka-month acquisition cohorts with retention, top customers.
- **Sales analytics** at `/analytics/sales` (B · 24 `241:30584`, session-based parts replaced — no defensible session denominator): placed vs recognised trend, category/brand performance with gross profit, AOV, repeat rate, discount/promotion impact, payment-method and channel split, recommendation attribution, ledger reconciliation figure. CSV + XLSX exports (openspout, streamed, formula guard). **Scheduled report emails** (`report_schedules`, `/analytics/schedules`): daily / weekly (Sat) / monthly in Dhaka time, staff recipients checked against the report's permissions, per-recipient copy for their role, idempotent per (schedule, period) via UNIQUE runs, `toymart:reports:send-scheduled` every 15 min on the `reports` queue. No rollup added — measured (1-year report 15.8 s under heavy load; 30 days 2.5 s); add a short cache only when real volume needs it.
- **Invoicing** (N-13) — `invoices` with gap-free `INV-YY-NNNNNN` / `PINV-YY-NNNNNN` per Dhaka year (counter row locked, verified on MySQL under concurrency), issued on first print, immutable snapshot of company, recipient, lines, totals and amount in words; reprints render the snapshot; one mPDF template EN/BN for customer and supplier invoices; invoice list `/invoices`, invoice company details at `/settings/invoice`; bulk print reports per-order results. N-14 (VAT/Mushak) not built — needs an accountant.
- **Automations** — review request N days after delivery (default 3, quiet hours 21:00–09:00 Dhaka, marketing consent, once per order) and birthday messages (10:00 Dhaka, explicit `customer_message_consents`, once a year; 29 Feb → 28 Feb). `automation_sends` UNIQUE makes reruns no-ops. SMS + WhatsApp templates in the library (WhatsApp "not submitted", stays disabled).
- **Phase 7 follow-ups** — title separator is one value in `config/seo.php` served to the storefront; Content↔Seo cycle removed via `SeoMetaStore` contract; one SEO/slug write path; blog category/tag/author SEO panel + storefront overlay; `htmlLimitedBots` set to the Next 16 default bot list (no-JS e2e now sends a crawler UA); sanitiser keeps safe relative links; CMS block images registered as media usages (delete refused while used); marketing lookups no longer need `orders.create`, scope chips show names; scheduled search lag check (5 min), nightly reindex (04:30 Dhaka) and cart prune (10 min); "Owed back to a customer" endpoint + P&L cash-card row.

**Owner decisions (2026-11-13):** customer segment thresholds confirmed (VIP ≥ ৳15,000 LTV, at risk after 90 days, lapsed after 180); LTV excludes delivery charges; one phone mask everywhere (`017****5678`, `PhoneNumber::masked()`).

**Owner decisions open:** dashboard best sellers on placed orders vs donut on recognised revenue — same basis?; activity feed reads `order_events` until something writes `activity_log`; title separator " | " vs " · " and Bangla site name in server titles; review requests as marketing (opt-in) vs order update; invoice issued on first print (edits after issue need credit notes); company details/BIN/licence for invoices; LTV bucket edges; cart-prune grace (7 days) and reindex time.

**Open:** conversion rate (no consent-free session denominator); XLSX for the older CSV-only reports (one line each via `ReportSheets`); credit notes; invoice logo not drawn; Bangla amount in words; storefront/admin screens to capture birthday consent; Bangla review SMS is 3 segments (short links would help); manual resend of a failed scheduled report; `OrderNumberGenerator` may share the first-of-year counter-row deadlock the invoice race test found; SSLCommerz gateway fee still not booked; no Figma frames for customer analytics, invoice list, schedules, automation schedule panel; dashboard Figma differences listed in the PR (KPI row 2, Export button, inbox items).

### Progress — Phase 8 batch 3: owner decisions on batch 2 (2026-11-13)

**Done, with passing tests** (3429 PHP, 8 skipped; 396 admin Vitest; 438 storefront unit; storefront Playwright profile + Bangla account specs; PHPStan, Pint, deptrac, typecheck, i18n lint clean):
- **Invoice corrections after issue** — `OrderEdited` (existing event, Ordering unchanged) → per-line delta: reductions (lines, discount, delivery) on a credit note `order_edited`, increases on a **supplementary invoice** (`invoices.kind`, same gap-free `INV` series, names the original, EN/BN PDF); one edit can give both; they net to the change in the grand total. Only edits after issue count (`invoices.order_event_id`). Courier return (`OrderReturned` with `courier_return`) → full-balance credit note `courier_return`. Balance = original + supplementary − credited. A refund after a cancellation/RTO credit closed the invoice issues nothing (no error). Lock order: CN counter → INV counter → original invoice row. Ledger untouched. One original per order via a generated column (MySQL has no partial index); old UNIQUE (type, order_id) dropped expand-then-contract.
- **Children's birthday reminders with an offer** — `customer_children` (optional nickname, month/day, optional year for the Figma age; max 6; hard delete, logged names blanked); admin + storefront CRUD (PII-gated); reminder to the parent when a child's birthday is ≤ `lead_days` away (default 7, placeholder), hourly from 10:00 Dhaka outside quiet hours, once per child per birthday, 29 Feb → 28 Feb. Offer = personal single-use coupon (`coupons.customer_id`, `not_for_customer` rejection) via `MarketingContracts\PersonalCouponIssuer`; **off until the owner sets it** (placeholders: 10 %, cap ৳300, min ৳0, 14 days). Templates updated (EN SMS 1 segment, BN 2 segments). The customer's own birthday message, DOB inputs and the admin DOB endpoint removed (`customers.date_of_birth` column kept, unused).
- **Consent confirmation method** — staff "yes" requires `confirmation_method` (phone_call | in_person | written | other) + optional note; shown in provenance.
- **Dashboard export** requires `reports.export` too.

**Owner decisions open:** order-screen print to include supplementary invoices?; "Collect on delivery (in addition)" on supplementary invoices clear for riders?; debit note vs supplementary invoice under VAT/Mushak (N-14, accountant); 2 Bangla SMS segments for the reminder with offer; send a missed reminder on the birthday itself (does today); withdraw the coupon when a child is removed (doesn't); Figma "Offers and birthday reminders" toggle — general marketing consent or birthday only (built: birthday only); children cap 6; WhatsApp gift line when no offer; offer values and lead days; storefront "Order updates on WhatsApp" / "SMS updates" switches have no backend; Bangla wording of new PDFs/labels/copy.

### Progress — Phase 8 batch 2 (2026-11-13)

**Done, with passing tests** (3397 PHP, 8 skipped; 389 admin Vitest — the known product-form load flake passes alone; 436 storefront unit; PHPStan, Pint, deptrac, typecheck, i18n lint clean):
- **Dashboard export** — `GET /admin/dashboard/export?format=csv|xlsx&locale=en|bn` with the dashboard's own range params; `DashboardSheets` built from the same `DashboardReport` array and permission scope as the JSON (cells equal the JSON; ledger figures equal the ledger to the poisha); a block the viewer cannot see is omitted, never zero; activity feed and needs-attention items left out (live work items, staff names); no PII. Export button + CSV/Excel menu in the header (B · 01). Needs `dashboard.view` only.
- **XLSX for the older reports** — P&L, P&L series, profitability, inventory valuation, courier performance take `format=csv|xlsx` (default csv, CSV byte-for-byte unchanged and asserted) and `locale`; `ReportSheets` builders; `Sheet` text columns keep SKUs/codes as text; money cells display `#,##0.00` (sales export too). Shared admin `components/ui/ExportMenu.tsx` on every report page. Scheduled P&L attachment now carries `ledger_types` like the CSV.
- **Credit notes** — `credit_notes`, gap-free `CN-YY-NNNNNN` per Dhaka year on `invoice_number_sequences`, immutable snapshot, issued after commit by Invoicing listeners on order cancellation after invoice issue (full remaining balance) and on refund paid (refunded lines + delivery; `adjustment` if capped). UNIQUE source = replay-safe; over-credit refused (409) with no number gap; never writes the ledger. EN/BN mPDF, `GET /credit-notes`, `/{id}`, `/{id}/pdf`; invoices show credited/balance/status; Credit notes tab in Invoices. `deptrac`: Invoicing may read Payment.
- **Birthday consent screens** — admin customer profile Birthday panel (DOB hidden without `customers.view_pii`, consent status + provenance, "customer agreed" confirmation required to record, withdraw); `PUT /admin/customers/{customer}/date-of-birth` (needs `customers.update` + `customers.view_pii`); storefront account profile birthday card (no-JS, never pre-ticked, never on checkout) via `GET|PUT /storefront/account/consents`. `SetMessageConsent` no longer writes a "withdrawn" row for a never-given consent.

**Owner decisions (2026-11-13):** an order edit after invoice issue gets a credit note for a reduction and a supplementary invoice for an increase; a courier return (RTO) gets a credit note; a refund paid before the first invoice print stays as is (no credit note; the invoice prints the full original amount); a credit note carries the company details current at its issue; birthday messages cover the customer's **children** (the Figma "My kids" card) and may carry an offer; birthday copy approved; the way staff confirmed consent is recorded; the dashboard export also requires `reports.export`; XLSX money cells display `#,##0.00` everywhere; Bangla export labels approved. Built in batch 3.

**Open:** Bangla amount in words on invoices/credit notes; VAT/Mushak credit-note format; no Figma frames for the export menu, credit notes, birthday panel/card; dashboard 768 header differs from Figma (picker row); scheduled reports offer no series/profitability/valuation types.

### Progress — Phase 7 batch 2: server-side tracking (2026-11-13)

**Done, with passing tests** (3096 PHP; 346 admin Vitest; 422 storefront unit; storefront Playwright 133 incl. 6 tracking specs; PHPStan, Pint, deptrac, typecheck, i18n lint clean). Full reference: `docs/api/tracking.md`.
- **K-01 Tracking core** (`app/Domain/Tracking`) — `tracking_events` (append-only except dispatch columns; UNIQUE event_id × name × source; payload stored as sent, PII already SHA-256 hashed), `order_tracking_contexts` (IP/UA/click ids blanked after 90 days, `toymart:tracking:prune-contexts` daily 03:40). One `DispatchPolicy` holds the dedup matrix: browser sends view_item/add_to_cart/begin_checkout to GA4; the server sends purchase/refund to GA4; Meta gets everything from both sides with the same `event_id`. Queued on `tracking` (3 tries, honours Retry-After, dropped as `skipped_stale` after 1 h). `purchase` fires once, on `order_placed` (default) **or** `order_delivered` per the setting; its `event_id` is UUIDv5 of `purchase:{order_number}` so the confirmation page's Pixel event matches; `refund` goes to GA4 only.
- **Drivers** — `Ga4MeasurementProtocolDestination` (send + debug-endpoint `validate()`; GA4 answers 2xx even for bad payloads) and `MetaConversionsApiDestination` (Graph v27.0, hashed user_data per Meta's normalisation, fbp/fbc/IP/UA unhashed, `test_event_code`; throttling codes retried). Null/Log destinations for CI. Secrets never in payloads, responses, logs or exceptions (tested). Unverified points marked `// UNVERIFIED:` and listed in tracking.md §4.4.
- **K-02 Consent** — storefront banner (opt-in default: nothing loads or is sent until accepted; `notice` mode optional; versioned by policy version; Google Consent Mode v2 defaults denied), "Cookie settings" footer link, tags via `next/script lazyOnload` only after consent, `/api/events` Route Handler forwards server-side with the storefront token (rule 28). Consent is read from the cookie, never trusted from the body. Checkout carries the tracking context, also without JS.
- **K-03 Admin** — `/integrations/tracking` (B · 25 `241:31086`: GA4, GTM, Meta cards with write-only secrets, event map with the purchase-trigger switch, consent settings, "Send test event" via the provider's validation path) and `/integrations/tracking/diagnostics` (24 h / 7 d counts per destination, consent-skip rate, Meta pairing rate, event log with allowlisted filters on Dhaka dates, event detail). Read access `settings.view`, write `settings.manage_integrations`; 403 role tests on every endpoint.

**Owner decisions open:** consent mode (opt-in default, per research §7.10) and the privacy-policy "Cookies and analytics" text; purchase on order placed vs delivered (default placed); staff-entered orders are sent only in `notice` mode; `tracking_events` age cap (none today — e.g. 13 months?); purchase value includes delivery charge; consent banner design (no Figma frame — built from Drawer `78:1198` / Modal `78:913`; "Accept all" primary vs "Reject" outline); GTM on/off setting; TikTok Pixel, Search Console and Merchant Center cards out of v1?; custom head/body scripts deliberately not built (stored-XSS surface). A GTM container must not also carry its own GA4/Meta tags or events double. Meta "Send test event" needs a test event code, and test events appear in Events Manager.

**Open:** verification against real GA4/Meta accounts (deduplication in Meta Events Manager is a Phase 7 gate test) once the owner creates them; BN consent copy needs native review; no circuit breaker for tracking providers (the existing one is courier-specific); diagnostics page has no Figma frame; no-JS cart adds send no add_to_cart.

### Progress — Phase 7 batch 1 (2026-11-13)

**Done, with passing tests** (2901 PHP + 7 mysql-group; 332 admin Vitest; 394 storefront unit; 123 storefront Playwright; PHPStan, Pint, deptrac, i18n lint clean):
- **CMS blocks + homepage builder** — 12 typed blocks with per-type schemas (hero, category/age tiles, product rail by rule, flash sale, brands, USP, promo split, testimonials, blog teaser, rich text, FAQ), EN+BN, per-locale/device visibility, Dhaka-time scheduling with a per-minute sweep, append-only published versions with revert, drag + keyboard reorder, live preview through the shared signed `App\Support\PreviewToken`; storefront falls back to the built-in home.
- **Static pages, menus, banners, testimonials** — pages with three templates and slug-history 301s, header/footer/mobile menu builder (falls back to the category tree), banners (only a text info strip on cart/checkout, rule 25b), testimonials.
- **Blog + buying guides** — posts/categories/tags/authors, scheduled publish, sanitised bodies, related posts, guide picks that hide when out of stock, Article/Breadcrumb JSON-LD.
- **SEO** — per-entity SEO panel with server SERP preview, metadata builder with canonical + hreflang en/bn/x-default on every page, JSON-LD builders with an offline required-fields validator, slug-history + redirect manager (no chains, no loops), locale-partitioned sitemaps (daily 03:00 Dhaka), robots.txt editor.
- **Promotions** — discount/free-delivery/bundle/tiered/BXGY with priority and stacking, explicit `allows_coupons` per promotion, per-phone and budget limits, coupon bulk generator + reports, flash sales with allotments claimed under locks (never oversold), countdown, abandoned-cart SMS (once per cart, consent + quiet hours) with restore link and recovery report.
- **Message templates library** (`/automations/templates`) — EN/BN editor with server preview/segments/cost, version history, WhatsApp approval status, custom marketing/manual templates.
- **Owner decisions applied:** risk thresholds and watch-list hold confirmed; verifying a held order confirms it; rider name/phone recorded on the shipment and named in the out-for-delivery SMS (no-rider variant otherwise, never the hotline); managers get Integrations; non-masking numeric REVE sender; ৳0.26 per SMS segment.
- **Integration fixes:** risk-profile rebuild retries on deadlock/duplicate insert; one global lock order for PlaceOrder and EditOrder (DATABASE-DESIGN §4.8); page and page-SEO cache tags aligned; one per-minute content sweep; cart-recovery retry routed correctly.

**Owner decisions open:** title separator (" | " vs " · "); static/policy page copy (seeded drafts are placeholders); `/store` landing page; per-page header colour; flash sales default `allows_coupons`; where guests consent to cart reminders (never checkout); `config/cart_recovery.php` values; Bangla no-rider SMS wording; managers editing credentials; template permissions and generic variable set; Meta approval for new WhatsApp templates.

**Open:** server-side tracking (GA4 MP, Meta CAPI, consent, diagnostics) — next batch; Search Console metrics and site audit; SEO providers for blog categories/tags/authors; CMS block images not registered as media usages; rich text blocks edited as raw HTML; several new admin screens have no Figma frame (message templates, banners, testimonials, promotion builders, blog terms).

### Progress — Phase 6 batch 1 (2026-11-13)

**Done, with passing tests** (2583 PHP; 258 admin Vitest; 270 storefront unit; PHPStan, Pint, deptrac, i18n lint clean):
- **Courier core** — `CourierGateway` contract + DTOs + `ShipmentStatus` transition table, registry, `NullCourierGateway` fixture harness; `shipments` (`SHP-YY-NNNNNN`, also the merchant ref); booking job outside any transaction with per-shipment lock, circuit breaker, unverified-provider block in production (rule 33a), unmapped/ambiguous area block (rule 32), uncertain booking → `submitted` and never re-posted; bulk → `submitted` (rule 24d); retry queue; courier delivered/returned applied through the existing order paths (`OrderDispatcher` shared with manual ship); `courier_charge`, `courier_cod_fee`, `courier_return_charge` in the ledger. `toymart:couriers:provider {code} --verify|--unverify` is the only way to verify a provider.
- **Pathao** — verified against the real sandbox with the published test credentials (token 90 days; refresh revokes the old token; order create 200; one sandbox order `DS131126MTS7DD` created), fixtures captured from real responses; location sync 64 cities / 1,323 zones / 38,520 areas, never auto-mapped; mapping screen at `/shipments/locations`; webhook answers 202 with Pathao's integration header.
- **Steadfast** — fixture-only from the published collection, `verified=false`, production booking refused; `courier_fraud_lookups` + `CourierHistoryLookup` returning "provider not connected" (never zero).
- **Courier admin** — settings at `/integrations/couriers` (cards, rate cards, write-only encrypted credentials, webhook URL), booking drawer with server quote, bulk "submit" with per-order results, shipment card, failed-bookings queue, 100×150 mm labels (Code 128, Bangla verified).
- **Status ingestion** — webhook verify-first → receipt → re-query (rule 24c); 15-minute poll; `courier_tracking_events`; storefront tracking milestones; out-for-delivery SMS; courier performance report.
- **Risk** — phone-keyed `customer_risk_profiles` rebuilt from events, weighted score with stored breakdown, one hold decision point (`OrderRiskAssessor`, absorbs the first-order rule), never auto-cancels; verification queue, phone lookup, rules form; courier panel separate and honest.
- **COD settlement** — import (CSV/XLSX/manual), match by consignment, variances flagged and decided by staff, confirm writes gross `cod_received` and any unbooked courier costs once.
- **Messaging settings** — SMS provider credentials entered at Admin → Integrations → Messaging (encrypted `settings`, env only a fallback, never returned or logged, audited as "changed"); test-SMS card; WhatsApp off by default so order updates go by SMS.

**Owner decisions open:** risk weights/thresholds (placeholders; first-order ৳3,000); watch-listed phones hold every order; verifying releases to pending; status-map judgement calls (Steadfast `cancelled` → returned, approval-pending not delivered; Pathao failed pickup/delivery → on hold); out-for-delivery `rider_phone` = hotline placeholder; delivery rate = delivered ÷ (delivered + returned); one rate card serves both customer charge and courier cost; who may open Messaging/Integrations (super_admin only today); REVE sender ID format and per-segment rate.

**Open:** Steadfast live verification and `fraud_check` (needs merchant account); duplicate-merchant-ref behaviour unverified (crash-window double booking); cancelling a parcel the courier holds; partial delivery settled by hand; courier portal export column names unknown (importer accepts our template + aliases); Integrations hub (B · 37) not built; several Figma frames not drawn (mapping, failed queue, settings) — built from existing parts.

**Phase 6 — Courier & Order Intelligence (24d).** ⚠️ **No merchant account exists for either provider (confirmed 2026-11-08).** Pathao has a real sandbox at `courier-api-sandbox.pathao.com`; **Steadfast has none — the `sandbox.api.steadfast.io` host in third-party docs is fabricated.** So: `PathaoGateway` is developed and verified against the real sandbox with captured fixtures; `SteadfastGateway` is developed against hand-authored fixtures from its published Postman collection, ships `verified: false`, and is blocked from production booking behind an explicit admin banner. `NullGateway` plus fixtures becomes the primary CI path for the whole fulfilment flow. Two items are deferred out of MVP and gated on real credentials: **Steadfast live verification (2d)** and **Steadfast `fraud_check` integration (1d)**. Until the latter lands, the risk score uses our own order history only, the courier panel renders "provider not connected" rather than a zero, and every first order from an unknown phone above a value threshold routes to the verification queue regardless of score. `CourierGateway` contract and provider config with encrypted credentials · `NullGateway` · Pathao driver (token issue/refresh, location sync, price calculation, order create, status) · Steadfast driver (create, bulk, status by consignment/invoice/tracking, balance) · `courier_locations` sync and the unmapped-area admin screen · one-click and bulk booking · label printing · charge preview · failed-booking retry queue · webhook ingestion with signature verification and idempotency · 15-minute reconciliation poll · status normalisation to one enum · circuit breaker per provider · customer tracking timeline · courier performance report · risk profiles keyed on phone · weighted scoring with a visible breakdown · courier lookup stored and displayed separately with its timestamp · verification queue · blacklist/whitelist · COD settlement reconciliation with variance flagging.

**Phase 7 — CMS, SEO & Marketing (33d).** Adds **per-locale SEO**: `hreflang` pairs plus `x-default`, separate title/description/OG per locale, locale-partitioned sitemaps, and Bangla `name`/`description` in Product schema. No audited competitor or marketplace has any of this. Typed CMS blocks with ordering, scheduling and live preview · homepage builder · static pages · menu builder · banners · media library · blog with categories, tags and authors · per-entity SEO panel with SERP preview · Product/Offer/AggregateRating, BreadcrumbList, Organization, Article, FAQPage, WebSite+SearchAction schema · slug history with automatic 301s · redirect manager · XML sitemap generation · robots.txt editor · pagination SEO · coupons and coupon builder · promotions engine with priority and stacking · flash sales with countdown · abandoned-cart detection and recovery · GA4 Measurement Protocol · Meta Conversions API · browser/server deduplication by shared `event_id` · consent gating · tracking diagnostics screen.

**Phase 8 — Reporting & Automation (25d).** Dashboard with date-range filtering · sales analytics · courier performance · customer analytics and LTV · invoice templates for customer and supplier, PDF, bulk print, numbering, company snapshot · nightly financial rollups · scheduled report emails · CSV/Excel export · WhatsApp Business templates · review-request automation · birthday reminders.

**Phase 9 — Testing & Optimisation (22d).** Includes a bilingual regression sweep of every MVP screen in both locales at both widths. Coverage completion including the full 403 matrix and every error path · query profiling and N+1 elimination · index verification under volume · caching · image optimisation · Lighthouse budgets met on every template · OWASP security pass · authorisation audit · webhook verification review · upload handling review · dependency audit · secret-handling review · SEO crawl and index-coverage audit · real mid-range Android QA over throttled 4G · axe plus manual keyboard and screen-reader passes in both languages · load test of concurrent checkout, flash-sale burst and bulk courier booking.

**Phase 10 — Deployment & Launch (10d).** Production provisioning and hardening · domain, SSL, DNS, SPF/DKIM/DMARC · production CI/CD with rehearsed rollback · encrypted off-host backups with a verified restore · Sentry, uptime checks, business alerts, log aggregation · live payment and courier credentials with production webhooks and a real low-value end-to-end order · GA4, GTM, Search Console, Bing, Meta CAPI, sitemap submission · content and catalog load · final UAT · launch checklist.

---

## 5. First Week After Approval

| Day | Work | Who |
|---|---|---|
| 1 | Gate 1 decisions. **Start payment-gateway onboarding and request courier API credentials the same day** — these have the longest external lead times. Resolve the Figma blocker. | Owner + lead |
| 1–2 | P3-SET-01…04 — monorepo, Docker, Laravel, domain structure, deptrac | Senior |
| 2–3 | P3-SUP-01…04 — Money, PhoneNumber, DhakaDate, Slug, with full unit tests | Senior |
| 2–4 | Phase 2 brand foundations and Tier 1 primitives in Figma | Designer |
| 3–4 | P3-DAT-01…04 — users, roles, BD geography, demo seeder | Senior |
| 4–5 | P3-API-01…04 — route groups, error handler, idempotency, rate limits | Senior |
| 4–5 | P3-ADM-01…02 and P3-STO-01…02 — frontend scaffolds on Phase 2 tokens | Mid frontend |
| 5 | P3-CI-01 — PR pipeline green. Week review and re-estimate against actuals. | Both |

Re-estimate at the end of week 1 and at every gate. The numbers in `DEVELOPMENT-ROADMAP.md` are a plan, not a commitment, until the first week's actual velocity is known.
