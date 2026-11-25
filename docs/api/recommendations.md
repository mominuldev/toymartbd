# Storefront recommendations (P10)

PRD §3.1a (X-01…X-11), CLAUDE.md rules 25b–25d. Three read surfaces, one resolver:
`App\Domain\Catalog\Recommendations\RecommendationResolver`. The storefront only shows what comes back.
It never filters, re-orders, caps or prices a recommendation (rule 1).

| Surface | Endpoint | Blocks / placement | Cap |
|---|---|---|---|
| Product page | `GET /api/v1/storefront/products/{slug}` → `recommendations` | `upsell` (`pdp_upsell`), `fbt` (`pdp_fbt`), `cross_sell` (`pdp_cross_sell`), `age_progression` (`pdp_age_progression`) | 2 · 1 block of 2–3 items · 4 · 4 |
| Cart | `GET /api/v1/storefront/cart/recommendations` | `cart_cross_sell` | 8 |
| Order confirmation | `GET /api/v1/storefront/orders/{number}/recommendations` | `confirmation_cross_sell` | 4 |
| Checkout | none, ever (rule 25b, PRD X-05) | | 0 |

All three need the Next.js server's token with the `storefront:read` ability: no token gets 401, and a token
without the ability gets 403. Errors are RFC 9457 problems.

## Guardrails (server-side, on every read)

Each guardrail applies to every surface:

1. **Eligible targets only.** The product is active, `published_at` has been reached, it is not deleted, it is
   listed in the online store (`channel_online_store`), and an active variant has available stock
   (`on_hand − reserved > 0`, through `AvailableStockQuery`). Sold-out, archived, draft, scheduled, deleted and
   POS-only products are dropped, never shown greyed out.
2. **Never the product itself.** On the cart and confirmation rails, never a product already in the cart or the
   order either.
3. **An upsell costs more, checked on read.** The target's lowest active variant price must be greater than the
   source's lowest. If a later price change turns an "upgrade" into a downgrade, the pair hides itself.
   `price_delta` = target `price` − source lowest price, and it is always > 0.
4. **Caps on read** come from `config/catalog.php` → `recommendations` (`upsell` 2, `cross_sell` 4,
   `age_progression` 4, `fbt_items` 2, `cart_cross_sell` 8, `confirmation_cross_sell` 4).
5. **One block per product** on the product page, with priority upsell > fbt > cross_sell > age_progression.
   `related` ("You may also like") leaves out every product already in `recommendations`.
6. **Never an upsell outside the product page.** The cart and confirmation rails read `cross_sell` pairs and
   co-purchase only.

## Product page: `recommendations`

```json
"recommendations": {
  "upsell":          [ Card & { "price_delta": "450.00" } ],
  "cross_sell":      [ Card ],
  "age_progression": [ Card ],
  "fbt": null | {
    "items": [ Card & { "variant_id": 123, "unit_price": "1000.00" } ],
    "bundle_total": "2350.00"
  }
}
```

`Card` is `StorefrontProductCard`, the same shape as listings and `related`. Every card is in stock.

- **upsell**: curated `product_relations` of type `upsell`, in `position` order.
- **cross_sell**: curated `cross_sell` pairs, in `position` order.
- **age_progression** ("next stage", X-07): curated `age_progression` pairs first. The rest are products in the
  same category whose `age_min_months` ≥ this product's `age_max_months`, nearest band first, then featured,
  then newest. "Same category" means the target's primary category is this product's primary category, or the
  target is filed under it (`category_product`). If the product has no `age_max_months`, only the curated pairs
  show.
- **fbt** (frequently bought together, X-03): `items[0]` is this product. It is followed by 1–2 products bought
  with it in at least `catalog.fbt_min_orders` (default 3) distinct non-cancelled orders over the last 180 days.
  These come from the `product_copurchases` projection, strongest signal first.
  - Each item carries the `variant_id` that "Add all to cart" posts, with `recommendation_source=pdp_fbt`.
    That variant is the item's only active variant, or the active default of several. It must be in stock.
  - A co-purchased product with several variants and no default is skipped.
  - `unit_price` is the variant's current price. That is its running flash-sale price when lower (the
    `flash_sale` the card shows), else its price.
  - `bundle_total` is the sum of the `unit_price`s. Other cart promotions (coupons, category discounts) are not
    applied, because the cart prices those.
  - `fbt` is **null** when the block cannot be shown whole: this product's purchasable variant is sold out, or no
    co-purchased product qualifies. The block hides; it never degrades.

**Cache.** The page stays cached under `product:{slug}` + `products` (docs/api/revalidation.md). Every product,
price, relation and stock change already sends `products`, so the recommendation blocks are purged with it.
The nightly `product_copurchases` rebuild changes `fbt` and the co-purchase fill without any product change,
so it should send `products` when it finishes. Otherwise the new pairs appear at the page's next revalidation.

**Queries.** The resolver costs a fixed number of queries, whatever the number of relations: relations,
co-purchase, next-stage ids, one load of every candidate with the card's eager loads, and one variant stock
query for the bundle. `tests/Feature/Storefront/Recommendations/ProductRecommendationsTest.php` asserts this.

## Cart: `GET /cart/recommendations`

Header `X-Cart-Token` (the cart token).

```json
{ "data": [ Card ], "placement": "cart_cross_sell" }
```

- The cart lines' curated `cross_sell` pairs come first, round-robin by `position`: every line's first pick
  comes before any line's second.
- Then co-purchase fill: products bought with any cart product in ≥ `fbt_min_orders` orders, strongest
  combined signal first.
- In stock only, never a cart product, never an upsell, ≤ 8.
- An empty cart gets `"data": []`.
- An unknown, malformed or missing token gets the same `404 not-found` as `GET /cart`.

## Order confirmation: `GET /orders/{number}/recommendations`

Header `X-Cart-Token`: the token of the cart that placed the order. This is the same proof of ownership as
`GET /orders/{number}` (`OrderController::ownedOrFail`). A wrong or missing token, an unknown number, or an
order placed more than 30 days ago gets the same `404 not-found` as the confirmation page. The endpoint shares
its `order-lookup` rate limit (30 a minute per IP).

```json
{ "data": [ Card ], "placement": "confirmation_cross_sell" }
```

It uses the same algorithm as the cart rail over the order's products, capped at 4.

## Attribution

Every add-to-cart from a block posts `recommendation_source=<placement>` on `POST /cart/items`. PlaceOrder stamps
it onto `order_items.recommendation_source` (rule 25d). Impressions, clicks and add-to-carts are posted to
`POST /recommendation-events`, and the server writes `purchased`.
