# Storefront product page — `GET /api/v1/storefront/products/{slug}`

P4-STO-05 (product detail) and the "Similar toys" row of P4-STO-07. Server-to-server only: the
Next.js storefront calls it from a Server Component with its `storefront:read` bearer token
(CLAUDE.md rule 28). The full schema is `StorefrontProduct` in `docs/api/openapi.json`
(`packages/api-types`: `Schema<'StorefrontProduct'>`).

## Which products answer

A **live** product: `status = active`, `published_at` reached, not deleted, and at least one active
variant — the same rule as a listing card, so every card has a page. Anything else (draft, archived,
scheduled, soft-deleted, variant-less, unknown) is **404** `not-found` (RFC 9457). Slugs are ASCII and
shared by both locales (rule 37); the response carries both languages (`name` / `name_bn`, …), the
storefront picks one.

| Status | When |
|---|---|
| 200 | live product |
| 401 `unauthenticated` | no token |
| 403 `forbidden` | token without `storefront:read` |
| 404 `not-found` | not live |

## What the API decides (rule 1 — the storefront only formats)

| Field | Rule |
|---|---|
| `variants[].price` | the variant price, decimal string |
| `variants[].compare_at_price` | the was-price, **null unless higher than the price** |
| `variants[].discount_percent` | whole percent off the was-price, **rounded down** (never overstates a saving): 4,990 → 4,250 is 14 |
| `variants[].saving` | was-price − price, decimal string ("You save ৳740") |
| `variants[].available` | `on_hand − reserved` summed over locations (StockAvailability, P4-CRT-05); 0 without a stock row |
| `variants[].stock_state` | `out_of_stock` at 0, `low_stock` at 1–5 (the exact count is shown), else `in_stock` |
| `in_stock` | some active variant has stock |
| `default_variant_id` | the variant marked default, else the first active one — the PDP's preselection |

Only **active** variants are listed. `attributes` are the variant-defining axes those variants use, in
attribute order, values in value order; each variant names its values in `attribute_value_ids`.
Out-of-stock variants **are** listed (the PDP disables them with a reason, never hides them —
UI-UX spec §6.2).

`images` are the ready images, primary first: `url` is the 1200 rendition (the 608 desktop stage at
2×), `thumb_url` the 160 one, plus WebP/AVIF `srcset` and both alts (each falls back to the other
language). `breadcrumbs` is the primary category's active path, root first; empty if that category is
archived. `specifications` is the free-form key/value list, empty values dropped.

## `related` — "Similar toys"

Up to **8** `StorefrontProductCard`s, best first, each **live and in stock** (rule 25c), never the
product itself:

1. `product_relations` of type `related` (curated and rule-generated), by `position`;
2. then live products in the same primary category — featured first, then newest;
3. then live products sharing any other category of this product (its L1 parent, typically).

`upsell`, `cross_sell`, `bundle` and `age_progression` pairs are **not** in this row: their own blocks
(frequently-bought-together, the upgrade pick) are not built yet. The desktop page shows the first 4.

Fixed query count regardless of variants, images or related cards (asserted in
`tests/Feature/Catalog/StorefrontProductDetailTest.php`).

## Caching

The storefront caches the response under the tags **`products`** and **`product:{slug}`**
(`docs/api/revalidation.md`): `product:{slug}` for the product itself, `products` because the page
carries a product grid (the related row), so a change to any listed product refreshes it. The selected
variant (`?variant=<id>`) is page state, not part of the API URL, so all variants share one cache entry.

**Stock is in this response.** A stock change must purge `product:{slug}` (and `products`, for the
cards), otherwise a sold-out variant stays selectable for up to the ISR hour. That is the inventory
listener's job (StockLevelChanged → revalidate), see P4-CRT-05.
