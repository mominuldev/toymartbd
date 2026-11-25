# Brief — Phase 10: storefront recommendations (5 parallel agents, 2026-11-25)

Builds PRD §3.1a (X-01…X-11) and CLAUDE.md rules 25b–25d. What already exists:
- `product_relations` (types upsell / cross_sell / … , position, reason_note, is_auto) and admin curation on the
  product form (`ProductRelationsWriter`, `ProductRelationResource`).
- `RecommendationPlacement` enum (`app/Domain/Catalog/Enums/RecommendationPlacement.php`).
- `cart_items.recommendation_source` → `order_items.recommendation_source` (PlaceOrder copies it); the storefront's
  `POST /api/cart/items` route handler already forwards `recommendation_source`.
- Sales analytics already reports recommendation attribution from `order_items`.

What is missing (this batch): the server resolver with guardrails, frequently-bought-together computed from
co-purchase, `recommendation_events`, the read endpoints, the storefront rails, and the funnel figures in admin.

## Rules every agent follows
- **No git.** The lead commits. Do not run `git add/commit/stash/checkout/reset`.
- **Stay in your file scope.** If you must touch a file outside it, keep the edit minimal and say so in your report.
- **Do not run** `composer openapi:export`, `npm run api-types:generate` or `npm run sync:storefront`: the lead runs them
  once at integration (several agents would otherwise overwrite `docs/api/openapi.json`). The OpenAPI test will be
  stale until then; that is expected.
- **Don't run the full Pest suite.** Run your own tests (`php artisan test --filter=…`) plus Pint, PHPStan and deptrac on your
  files. The lead runs everything at the end.
- Never touch `public/hot`; never run prettier over existing files (180-col single-quote style by hand).
- Rule 1: clients never compute money. Every price, delta and bundle total comes from the API.
- Rule 26: semantic tokens only. Rule 35: every string through i18n, in **both** EN and BN.
- Report at the end: files changed, tests run and their results, deviations from Figma, open questions.

## The contract (all agents build to exactly this)

Product card = the existing `StorefrontProductCardResource` shape (call it `Card`).

### 1. Product page — `GET /api/v1/storefront/products/{slug}` gains `recommendations`
```json
"recommendations": {
  "upsell":          [ Card & { "price_delta": "450.00" } ],   // ≤ 2. "Upgrade to". delta = target price − source price, server-computed, always > 0
  "cross_sell":      [ Card ],                                // ≤ 4. "Goes well with"
  "age_progression": [ Card ],                                // ≤ 4. "When they're ready for the next stage"
  "fbt": null | {                                              // ≤ 1 block. null when it cannot be shown whole
    "items": [ Card & { "variant_id": 123 } ],                 // 2–3 items; items[0] is THIS product (its default/only variant)
    "bundle_total": "2350.00"                                  // sum of the items' current unit prices, server-computed
  }
}
```
Guardrails (resolver, server-side): drop out-of-stock, archived, unpublished, not-listable targets; never the product itself;
upsell target price must be > source price on read too (write-time check already exists or is added by agent 3);
caps enforced on read; a product appears in at most one block (priority upsell > fbt > cross_sell > age_progression);
`related` (existing "You may also like") excludes anything already shown in `recommendations`. FBT is shown only when every
item is in stock and has a single default purchasable variant; otherwise `null` (the block hides, never degrades).
Age progression: same category (or shared skill), `age_min_months` ≥ source `age_max_months`, nearest band first, curated
`age_progression` relations first.

### 2. Cart — `GET /api/v1/storefront/cart/recommendations` (header `X-Cart-Token`)
`{ "data": [ Card ], "placement": "cart_cross_sell" }` — ≤ 8, from the cart lines' curated `cross_sell` relations, then
co-purchase fill; excludes products already in the cart; in stock only. **Never upsell** (rule 25b). Empty cart → `[]`.

### 3. Order confirmation — `GET /api/v1/storefront/orders/{number}/recommendations`
Same access check as `GET /orders/{number}` (reuse its request/authorisation). `{ "data": [ Card ], "placement": "confirmation_cross_sell" }`, ≤ 4.

### 4. Events — `POST /api/v1/storefront/recommendation-events`
```json
{ "session_id": "opaque 16–64 chars [A-Za-z0-9_-]",
  "events": [ { "placement": "pdp_cross_sell", "event": "impression|click|add_to_cart",
                "source_product_id": 12, "recommended_product_id": 40, "variant_id": null } ] }   // 1–50 events
```
→ `202 Accepted`, empty body. FormRequest validation; placements from `RecommendationPlacement::values()`; `purchased` is
**not** accepted from clients — the server writes it. Written by a queued job (queue `index`), never inline. Throttled.
`customer_id` from the customer bearer when present. For cart/confirmation placements `source_product_id` may be null.

### 5. Tables (owned by agent 2; agents 1 and 3 read them)
- `recommendation_events`: `id`, `placement` (string 32), `event` (string 16), `source_product_id` NULL, `recommended_product_id`,
  `variant_id` NULL, `session_id` NULL (string 64), `customer_id` NULL, `order_id` NULL, `occurred_at` (datetime, UTC).
  Indexes `(placement, occurred_at)`, `(source_product_id, recommended_product_id)`, `(order_id)`. Append-only (no updates/deletes).
- `product_copurchases`: `product_id`, `other_product_id`, `orders_count` (unsigned int), `last_ordered_at`, `computed_at`;
  PK/unique `(product_id, other_product_id)`, index `(product_id, orders_count)`. A rebuildable projection over the last 180 days
  of non-cancelled orders (both directions stored). Model `App\Domain\Catalog\Models\ProductCopurchase`.
  FBT minimum signal: `orders_count >= 3` (config `catalog.fbt_min_orders`).

### 6. Storefront (shared files scaffolded by the lead in `../toymartbd-storefront/lib/recommendations/`)
- `types.ts` — provisional TS types for the contract above (the lead swaps them for generated api-types at integration).
- `track.ts` — client helper `trackRecommendation(events)` that batches to the storefront route handler
  `POST /api/recommendation-events` (owned by agent 5) with `navigator.sendBeacon`, and `observeImpressions(el, events)`
  (IntersectionObserver, ≥50% visible, once per element per page view). Agent 5 implements it; agent 4 only imports it.
- Every add-to-cart from a rail posts `recommendation_source=<placement>` (the existing cart form already forwards it).
- JS budgets are at the ceiling (category 211.8/212 kB, checkout 209.4/210). Rails are **server components**; only the tiny
  impression/click island is client. Import `format`/`localizedPath` from `lib/i18n/format` and `lib/i18n/config`, never the
  i18n index, in client components. Nothing on checkout (rule 25b).

## Agents and scopes
| # | Agent | Repo | Scope (files it owns) |
|---|---|---|---|
| 1 | Resolver + read endpoints | main | `app/Domain/Catalog/Recommendations/**` (new), `StorefrontProductResource` + product controller wiring, new `Storefront/CartRecommendationController`, `Storefront/OrderRecommendationController`, their Resources, the 2 GET routes in `routes/api_v1_storefront.php`, `config/catalog.php` keys, `docs/api/recommendations.md` (new), tests `tests/Feature/Storefront/Recommendations/**`, `tests/Unit/Catalog/Recommendations/**` |
| 2 | Events + co-purchase projection | main | migrations for both tables, `RecommendationEvent` + `ProductCopurchase` models, `RebuildProductCopurchases` action/command (`toymart:catalog:copurchases`, scheduled nightly 03:30 Dhaka in `routes/console.php`), events FormRequest + controller + job, the POST route, a listener that writes `purchased` events on order placement (one per order line with a `recommendation_source`, via the existing order-placed domain event), `DATABASE-DESIGN.md` as-built note, tests |
| 3 | Admin: funnel + curation polish | main | Reporting query + resource for per-placement impressions → clicks → add-to-cart → purchased units → revenue (revenue from `order_items` by `recommendation_source`, recognised-on-delivery rule 10), shown in the existing Sales analytics recommendation section (`resources/js/admin/features/analytics/sales/**`) with admin i18n EN+BN; product form relations: thumbnails, reason note visible, upsell price check message (`resources/js/admin/features/catalog/products/**` relation parts only), plus role tests |
| 4 | Storefront PDP | storefront | `components/product/recommendations/**` (new): Upgrade to (shows `+৳delta` from the API), Goes well with, Frequently bought together (items, ticks are display only, "Add all to cart" posts each `variant_id` with `recommendation_source=pdp_fbt`, total = `bundle_total`), Next stage; wiring in `app/[locale]/products/[slug]/page.tsx`; i18n keys under `pdp.reco*`; unit + e2e tests |
| 5 | Storefront cart + confirmation + tracking | storefront | `lib/recommendations/track.ts` implementation, `app/api/recommendation-events/route.ts` (session cookie `tm_rsid`, httpOnly, 30 days, forwards to the API with the server token), `lib/recommendations/server.ts` fetchers for cart + order, cart cross-sell rail (`components/cart/CrossSellRail.tsx`) on the cart page, confirmation rail on `app/[locale]/orders/[number]/page.tsx`; i18n keys under `cart.reco*` / `order.reco*`; unit + e2e tests |

## Figma (Smart Junior file `2b6PVOOapPbcaKYIdHWZup`, Vivid page `34:5658`)
Product desktop `34:6394` / mobile `34:6677` (FBT block and "You may also like" are inside; Light refs `23:4210`, `23:4279`, `24:4517`);
cart desktop `34:6773` / mobile `34:6922`, cross-sell rail `34:6914` (Light `25:4945`); order confirmed `34:7265` / `34:7307`.
There is no Figma frame for "Upgrade to" or "Next stage": build them in the same card language as FBT/related and list them
as deviations. Load the `figma:figma-design-to-code` skill before `get_design_context`; screenshot-compare with the Playwright
CLI (installed Chrome, channel `chrome`) — never Claude in Chrome. Record deviations for `docs/_design/07-smart-junior-storefront.md` §16.4 in your report (the lead writes the section).
