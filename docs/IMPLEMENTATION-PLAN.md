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

**Owner decisions open:** overstock = `available > reorder_point + 2 × reorder_quantity` (needs reorder_quantity > 0); `Aa` in the rich-text toolbar is "clear formatting"; a saved simple product cannot become variable (API refuses the type change); media delete/bulk download limited to super_admin + manager; storage quota unset.

**Disabled pending later features:** Transfer stock / Transfer N, Create PO, product/QR embed and AI write in the description toolbar.

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

**Phase 5 — Inventory & Operations (35d).** Stock levels and append-only ledger with locking · nightly reconciliation job with alerting · suppliers · purchase orders with partial receipt and landed-cost apportionment · weighted-average costing on receipt · stock adjustments with approval · damage and loss · low-stock alerts and reorder points · inventory valuation · stock-overview screens · financial ledger with the revenue/cash separation · expenses and categories · `order_financials` projection · product-, category- and order-wise profitability · P&L daily/weekly/monthly/yearly · supplier payments · returns and refunds with approval threshold, restocking, ledger reversal and gateway refund.

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
