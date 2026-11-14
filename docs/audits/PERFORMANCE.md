# Performance audit (Phase 9, batch 1)

**Scope:** N+1 elimination, query profiling and index verification under volume, caching, image optimisation, and storefront performance budgets in CI (CLAUDE.md rule 29, `UI-UX-DESIGN-SPEC.md` §8).
**Date:** 2026-11-14.

**Tools added**
- `database/seeders/PerformanceVolumeSeeder.php` builds the volume dataset. It is never part of `DatabaseSeeder`, refuses to run in production, and its sizes can be overridden with `PERF_PRODUCTS`, `PERF_CUSTOMERS` and `PERF_ORDERS`.
- `scripts/perf/profile.php` profiles endpoints. It runs 36 storefront and admin GET endpoints in-process. For each one it reports the median time over N runs, the query count, repeated SQL, SQL time, **lazy loads on real volume** and the slowest statement. `--explain` adds the query plan.
- `tests/TestCase.php` is the N+1 guard (see P-02).
- `toymartbd-app/storefront/e2e/performance-budgets.spec.ts` is the budget gate (see "Budgets in CI").

**Volume used:** `migrate --seed` plus the demo data, then the volume seeder on a private SQLite file:
- 5,016 products and 6,028 variants;
- 20,043 customers;
- 50,130 orders over 365 days;
- 150,309 order_items;
- 123,274 ledger rows;
- 29,966 invoices;
- 363 daily summaries.

The seeder bulk-inserts rows, so the build invariants do not hold on that file. The file was used only for plans and timings.

**Measurement caveat.** Five other agents shared the machine. The "before" profile ran at load average **≈30**; the "after" profile ran at **≈4**. Wall-clock before/after is therefore not like-for-like. The **query counts are exact**. The factors that matter were re-measured at the same load, component by component (P-03 to P-05). SQLite plans are not MySQL plans. Index decisions were reasoned against the MySQL 8.4 schema, and the evidence is given per finding.

## Findings

| # | Severity | Where | Finding | Fix | Test that proves it | Status |
|---|---|---|---|---|---|---|
| P-01 | **High** (budget) | Storefront JS, all templates | **§8's JS budgets cannot be met on Next.js 16 + React 19.** The chunks every App Router page loads before any storefront code (React DOM 72 KB, router runtime 43 KB, scheduler/idle-callback 14 KB, interop helpers, turbopack runtime) total **~152 KB gzipped**. That is above §8's 140 KB (home, category) and 120 KB (checkout). Measured per template **after** the P-11 cut: home 207.7, category 202.3, PDP 213.5, cart 200.6, checkout 202.1 KB (transfer size, gzip). | **Escalated (owner decision).** Every other §8 row is enforced as written. JS is held at a ratchet ceiling (measured + ~5 %, `JS_CEILING_KB` in the spec) so it can only shrink. §8 is annotated to say so. The options are for the owner: re-baseline the JS row, or move the storefront off the App Router runtime (the Gate 3 Blade + Livewire option). | `performance-budgets.spec.ts` | **Needs owner** |
| P-02 | Medium | Test suite | There was no N+1 guard. A lazy load in a list endpoint was invisible to the tests. | `Model::preventLazyLoading()` is on for **every** test (`tests/TestCase.php`), and a violation fails the test. There is an explicit allowlist (`LAZY_LOADING_ALLOWLIST`), and it is **empty**: every violation found was fixed. `LAZY_LOADING_LOG=/path` switches to survey mode, which records each violation instead of failing. The profiler also enables the guard on the volume dataset. The result was **0 lazy loads across all 36 endpoints**. `preventAccessingMissingAttributes` was **not** enabled. Resources legitimately probe optional selected columns (`listing_in_stock`, report aliases) with `getAttribute()`, so that mode would turn correct code into exceptions. | Full suite in strict mode, directory by directory. Every directory passed except the pre-existing failures listed under "For other areas". | **Fixed** |
| P-02a | Medium | `CourierLocationResolutionResource` (resolve → ambiguous candidates), `CourierLocationController::suggestZones` | N+1: each candidate or suggested courier zone lazy-loaded `district` and `upazila`, which is 2 queries per row. | `loadMissing(['district','upazila'])` over the whole list. | `tests/Feature/Fulfilment/Pathao/CourierLocationApiTest.php` under the guard (23 passed; it failed before the fix). | **Fixed** |
| P-03 | **High** | `Finance/Reports/Profitability::report()`, `Reporting/Queries/Dashboard/PlacedOrders::scan()` and `bestSellers()` | `lazyById(1000)` over a **join** re-runs the whole join and sort for every 1,000-row chunk. P8 had already found this and fixed it in `DeliveredLines`, but three loops were missed. Profitability (365 d) issued **91 queries (89 repeated)**. Dashboard (30 d) issued 59 queries (31 repeated). | One streamed `cursor()` query, ordered by id, which is the same pattern as DeliveredLines. | Profitability 365 d went from **91 queries to 1**. Dashboard 30 d went from **59 to 34**. The existing Reports, Reporting and Finance suites (report figures reconcile to the ledger) all pass. | **Fixed** |
| P-04 | **High** | `App\Support\Money`, `Finance/Reports/ReportMoney` | Every report loop spends most of its time in Money. `plus()` with `ReportMoney::of()` cost **13.4 µs**: two regexes and three bcmath calls to re-round a value that was already canonical. A year of sales analytics runs about a million of these. | Fast path for values already in canonical form (two decimals, no leading zero, ≤ 12 integer digits, not `-0.00`). `plus()` and `minus()` check only the range and negative zero, because bcmath at scale 2 already returns canonical form. The behaviour is identical. | **13.4 → 2.85 µs** per op (4.7×). `tests/Unit/Support/MoneyFastPathTest.php` pins the edges (negative zero, leading zeros, DECIMAL(14,2) overflow on `of()` and on `plus()`) and checks 5,000 random sums against a bcmath reference. The existing `MoneyTest` and pricing math pass. | **Fixed** |
| P-05 | **High** | `Reporting/Queries/Sales/DeliveredLines::add()` | **O(n²).** The accumulator was passed and returned **by value**. Writing its `orders` set (up to one key per order in the range) therefore copied the whole set on every line. A year of sales analytics took **79.5 s** in this loop alone at 90 k lines, against 2.2 s of SQL. | Accumulate by reference. | Same load, component timing: **DeliveredLines 79.5 s → 1.7 s**, and PlacedOrders 18.8 s → 2.6 s (P-04). The **sales analytics (365 d) endpoint went from 64.4 s to 2.8 s**. The figures are unchanged: the Reporting and Reports suites pass, including the ledger reconciliation tests. | **Fixed** |
| P-06 | **High** (latent) | `Finance/Services/Receivables::summary()` | The delivery date for aging was a second query with **every open COD order id as a bound parameter**. MySQL refuses more than 65,535 placeholders, so the receivables card would start returning **500s** at roughly 1.3 years of COD volume. `total()` also re-ran the whole collectable aggregation. | One ledger pass returns the collectable amounts and the delivery date (`MIN(CASE … sale_revenue …)`). The received total is a single SUM. | Receivables went from 16 queries to 14, with 5.3 s of SQL at load 30 before and 0.19 s at load 4 after. `tests/Feature/Finance/ReceivablesSummaryTest.php` pins aging buckets, open count, recognised/received/outstanding, `total()` and `forOrder()`. Finance suite: 92 passed. | **Fixed** |
| P-07 | Medium | `financial_transactions` (P&L statement, sales analytics, dashboard ledger figures) | Range sums over the ledger by `occurred_on`, filtered on the revenue/cash flags and grouped by type and direction. On MySQL, `(occurred_on, type)` finds the rows, but each one costs a clustered-index lookup for the flags and the amount. For a year's range the optimizer falls back to a full scan. | Migration `2026_12_03_300000`: covering index `ft_report_cover_idx (occurred_on, is_recognised_revenue, is_cash_movement, type, direction, amount)`. It builds online in MySQL 8 (INPLACE, LOCK=NONE) and adds negligible write cost to an append-only table. | Volume file, a year of cash rows (30,103): **61 ms via the old index, 6 ms via the covering one** (SQLite, forced with `INDEXED BY`). SQLite's planner does not choose it on its own (it prefers a skip-scan), but MySQL's cost model prefers an index-only range. Needs confirming with `EXPLAIN` on staging MySQL. | **Fixed** (MySQL plan: needs owner staging) |
| P-08 | Low | `invoices` list | `type IN (sales, purchase) ORDER BY issued_at DESC, id DESC LIMIT 26`. `(type, issued_at)` cannot give that order across two types, so every invoice was sorted on each page load. | Migration `2026_12_03_300100`: `invoices_issued_at_id_idx (issued_at, id)`. | Plan before: `SCAN invoices` + temp B-tree. After: `SCAN invoices USING INDEX invoices_issued_at_id_idx`, with no sort, stopping after one page. | **Fixed** |
| P-09 | **High** (bug found under volume) | Admin risk queue `GET /risk/queue` | **500 once more than 25 orders are held.** The page swapped its Order rows for `HeldOrder` objects, and the cursor paginator reads `placed_at` and `id` off the last row to build `next_cursor`. Those objects have neither property. | `HeldOrder::__get` and `__isset` delegate to the order. | `tests/Feature/Risk/VerificationQueuePagingTest.php`: 27 held orders page as 25 + 2 with no overlap. | **Fixed** |
| P-10 | Medium | Storefront product cards outside `ProductListing`: search fallback, flash sale, home rails, PDP related, search suggestions, wishlist, blog picks | `in_stock` ran one stock query **per card**. The search fallback issued **35 queries, 23 of them the same statement**. | `StorefrontProductCardResource::primeStock()` resolves stock for a whole list in one query. Every list caller calls it. (A `newCollection` override was tried first, but it broke Scramble's schema, so it was reverted.) | Search fallback went from **35 queries to 12**, with **0 repeated**. Flash sale went from 17 to 14. `tests/Feature/Storefront/ProductCardStockQueryTest.php`: 5 cards, 1 stock query, correct in/out of stock. OpenAPI document unchanged. | **Fixed** |
| P-11 | Medium | Storefront `components/motion/Reveal.tsx`, `components/delivery/DeliveryEstimatorClient.tsx` | The `motion` package (~21 KB gzipped of JS) was used for two opacity/transform effects: listing reveal and the PDP delivery estimate. | Replaced with the Web Animations API plus IntersectionObserver in `lib/motion.ts` (`play`, `onceInView`). Durations and easing still come from the motion tokens, and reduced motion is still honoured. | PDP JS went from **230.0 to 213.5 KB**, and the motion chunk is gone from listing and PDP. Storefront typecheck clean; storefront unit tests 451 passed. The `motion` dependency was then removed from `package.json` and the lockfile in Phase 10 prep (agent D). | **Fixed** |
| P-12 | Medium (gate correctness) | Budget emulation | The first budget runs showed home LCP at 1.6–2.6 s because the hero was downloaded **twice** (a 1080w preload and a 640w img). The cause was the Desktop Chrome project's 1920×1080 `screen` surviving the mobile override: Chromium's preload scanner sized the preload for that screen. A real phone's screen equals its viewport, so this does not happen on devices (checked separately: one 640w fetch). | The spec sets `screen` to 412×823, as Lighthouse's moto g power profile does. | Home LCP is ~1.0 s in both locales, with one hero fetch. | **Fixed** |
| P-13 | Low | Bangla pages | Bangla pages download **4 Bangla font files, 316 KB** (Anek Bangla 156 KB + 33 KB, Baloo Da 2 90 KB + 43 KB). The 156 KB Anek Bangla Bengali subset also loads on **English** pages, because the language toggle's "বাংলা" label needs it. Swap gives CLS **0.030** on `/bn` (budget 0.05). | Not changed. The fonts load after the LCP (`display: swap`, not preloaded), and LCP and CLS are within budget. Options: render the toggle label in the system font, which saves 156 KB on every English page view, or subset the fonts to the glyphs actually used. Both are design calls. | Budget spec (CLS reported) | Accepted, design follow-up |
| P-14 | Low | Admin orders `filter[search]`, customers search | `LIKE '%term%'` on name and `LIKE '%digits'` on phone cannot use an index, so both are full scans. At 50 k orders that is 60–180 ms on SQLite under load. | Not changed. This is acceptable at the planned volume. If it grows, add a reversed-phone generated column with a prefix index, or a full-text index on the name. | — | Accepted |
| P-15 | Low | Customers list | Lifetime value is a derived table over orders ⨝ order_financials, aggregated three times per request (page, total, segment counts). It took 100–180 ms at 20 k customers / 50 k orders. Segments depend on it, so it cannot be scoped to the page. | Not changed. If it grows, maintain `lifetime_value` on `customers` from the ledger projector. That is a schema change and should get its own PR. | — | Accepted |
| P-16 | Low | `InventoryValuationReport` | Binds every variant id in one `whereIn`. That is fine at catalogue sizes (≤ 10 k) but has the same ceiling as P-06 at 65 k variants. | Not changed. Note: chunk it if the catalogue ever approaches that size. | — | Accepted |

## Endpoint profile on the volume dataset (SQLite)

The profile runs warm, takes the median of 3 runs, and authenticates as owner.

| Endpoint | Before: queries (repeated) | After: queries (repeated) | Before ms (load ≈30) | After ms (load ≈4) |
|---|---|---|---|---|
| sf products listing p1 / category / brand | 19–20 (0) | 19–20 (0) | 137–225 | 80–122 |
| sf search fallback | **35 (23)** | **12 (0)** | 62 | 34 |
| sf product page | 20 (0) | 20 (0) | 27 | 14 |
| sf flash sales | 17 (5) | 14 (2) | 13 | 8 |
| admin dashboard 7 d / 30 d | 33 (6) / **59 (31)** | 28 (4) / **34 (9)** | 384 / 1,795 | 173 / 542 |
| admin orders list / status filter | 4 / 4 | 4 / 4 | 57 / 58 | 31–107 / 35 |
| admin customers list | 7 (3) | 7 (3) | 156 | 128 |
| admin sales analytics 30 d | 17 (8) | 17 (8) | 886 | 347 |
| admin sales analytics **365 d** | 35 (22) | 35 (22)¹ | **64,390** | **2,782** |
| admin customer analytics | 10 (2) | 10 (2) | 2,518 | 406 |
| admin P&L 365 d | 14 (3) | 14 (3) | 1,974 | 566 |
| admin profitability **365 d** | **91 (89)** | **1 (0)** | **9,410** | **1,335** |
| admin receivables | 16 (5) | 14 (4) | **11,619** | **301** |
| admin invoices list | 3 | 3 | 81 | 14 |
| admin risk queue | **500** (bug P-09) | 5, 200 OK | — | 12 |

Lazy loads on volume: **0 on every endpoint** after the fixes.

¹ The repeats are deliberate. The 1,000-id chunks of the repeat-purchase and new-customer lookups keep every statement far below MySQL's placeholder limit. 22 repeats per year-long report is fine.

## Index review against the MySQL schema

These indexes already serve their hot queries, and no change is needed:
- `orders (status, created_at)`: board filter + sort.
- `orders (created_at)`: default sort, with InnoDB's implicit PK suffix giving `(created_at, id)`.
- `orders (placed_at, status)`: sales/dashboard range scans.
- `orders (customer_id, created_at)`: profile, first-order, repeat-purchase.
- `orders (delivered_at)`: delivered-lines range.
- `order_items (order_id)`: the correlated units subquery and joins.
- `stock_levels (variant_id, location_id)` unique.
- `products (primary_category_id, status)`, `(status, published_at)`.
- `customer_risk_profiles (phone)` unique.

`invoices.original_invoice_id` has no index on SQLite (the profile showed `SCAN invoices`). MySQL creates one automatically for the foreign key, so no migration is needed. Two indexes were added (P-07, P-08), and both migrations are in this agent's block (`2026_12_03_300000`, `…300100`).

## Caching

Confirmed, no change:
- **Storefront API responses** are cached by Next.js ISR with cache tags, with a 1 h ceiling (`lib/api.ts` `cached()`). Domain events (`BrandChanged`, `CategoryChanged`, `ProductChanged`, `StockLevelChanged`, `PromotionChanged`, CMS publish) revalidate the affected tags. Per-request data (cart, checkout, tracking, account) goes through `noStore`, so nothing per-customer is cached.
- **Server-side**, these are already cached with invalidation: the home layout (`HomeLayoutCache`), the redirect map (300 s, forgotten on save), spelling suggestions and the low-stock count.
- **Menus and category tree** cost 3–11 queries and under 10 ms on volume, and ISR fronts them. A Laravel cache would add an invalidation surface for no measurable gain.
- **Not cached on purpose:** the admin dashboard, reports, P&L, receivables and customer figures. They must reconcile with the ledger at read time (rule 10). After P-03 to P-06 they are fast enough without a cache.

## Images

Confirmed, no change:
- **Pipeline.** The pipeline (`config/media.php`) re-encodes every upload. It writes WebP and AVIF at 160, 320, 480, 800 and 1200 px, never upscaling, with metadata stripped.
- **Card and gallery markup.** Cards and the gallery emit `srcset` for both formats with explicit `width` and `height`. Card resources use the 480 rendition, cart lines the 160 and order lines a thumbnail.
- **Lazy loading and priority.** The storefront lazy-loads everything below the fold. Only the LCP image is eager with high priority: the home hero (`preload`), the first CMS hero slide, the PDP gallery slot 1 (`preload()` with `imageSrcSet`) and the first two listing cards.
- **Static art.** Static art goes through `next/image` as AVIF/WebP.
- **Above-fold image bytes, measured:** home 12.1 KB against a budget of 250.

The one image issue found was the gate's emulation (P-12).

## Budgets in CI

`e2e/performance-budgets.spec.ts` is part of the Storefront job's existing E2E step (step name extended; the four job names are unchanged). It reuses that step's `next build`, so there is no second build. It adds **~1.6 min** to the job.

**What it measures**
- **Templates:** home (EN + BN), category, PDP, cart, checkout, against the mock API.
- **Profile:** Lighthouse's mobile profile applied as real devtools throttling: 412×823 @ DPR 1.75, 4× CPU, 150 ms RTT, 1.6 Mbps down.
- **Runs:** median of 3, which is LHCI's default.
- **Metrics:** LCP, CLS, INP, JS transfer and above-fold image bytes. INP is measured on one tap after load, and TBT is reported alongside.

It uses Playwright's CDP rather than the Lighthouse CLI. The numbers come from the same Chromium APIs, it needs no new dependency, and it fits the time budget.

**Final local run (green, 5 passed)**

| Template | LCP (budget) | INP | CLS (budget) | JS KB (§8 / ceiling) | Images KB (budget) |
|---|---|---|---|---|---|
| Home EN | 972 ms (2,500) | 24 ms | 0.000 (0.05) | 207.7 (140 / 218) | 12.1 (250) |
| Home BN | 1,016 ms (2,500) | 24 ms | 0.030 (0.05) | 207.7 (140 / 218) | 12.1 (250) |
| Category | 1,020 ms (2,500) | 24 ms | 0.000 (0.05) | 202.3 (140 / 212) | 0 (300) |
| PDP | 1,040 ms (2,500) | 24 ms | 0.000 (0.05) | 213.5 (160 / 224) | 0 (250) |
| Cart | 976 ms (2,000) | 24 ms | 0.000 (0.02) | 200.6 (120 / 210) | — |
| Checkout | 932 ms (2,000) | 24 ms | 0.003 (0.02) | 202.1 (120 / 210) | — |

The mock catalogue has no product photos, so image bytes on category and PDP are the placeholders. Production photo weight is bounded by the 480/800 renditions above.

## Needs owner

- **P-01: re-baseline §8's JS row**, or decide on the stack. Until then CI holds the ratchet ceilings.
- **Field data.** Collect CrUX / RUM on real mid-range Android over 4G in Bangladesh (Core Web Vitals field data, roadmap Phase 9 "real devices"). The gate here is lab-only, on a CI runner against localhost.
- **Staging MySQL.** Run `EXPLAIN` on staging MySQL 8.4 with production-like volume to confirm the P-07 covering index is chosen, and to re-run `scripts/perf/profile.php` there. SQLite plans were the local proxy.
- **P-13.** Decide on the Bangla toggle label's font and on font subsetting (design).

## For other areas

- **F-7 (agent 1, media lock order).** This agent did **not** edit the media pipeline Actions (`Crop/Update/DeleteProductImage`, `ReplaceMedia`, `UpdateMedia`, `CompleteMediaRenditions`, `AttachMediaToProduct`). The image work here was confirmation only, so F-7 is left to the lead as offered.
- **Pre-existing failures seen while sweeping the suite.** They are unrelated to lazy loading, and they reproduced with the guard off:
  - `tests/Feature/Notification/BirthdayRemindersTest`: 3 failures during the sweep, in another agent's in-progress area.
  - `tests/Feature/Invoicing/InvoiceIssueTest`: one transient failure; it passes on re-run.
