# Promotions, flash sales and the coupon builder (P7)

Builds on [cart.md](cart.md) (the pricing pipeline). Every figure is computed server-side by
`PricingPipeline` (rule 1); clients render the strings they are given.

## Pipeline, as of P7

```
base → promotions (priority, stacking, limits) → coupon (minus non-combinable lines)
     → delivery (free-delivery promotion or coupon) → total
```

1. **Promotions** (`Marketing\Services\PromotionEngine`). Running promotions — active, `starts_at ≤ now <
   ends_at` (half-open window, stored UTC, entered and shown in **Dhaka time**) — in **descending
   `priority`**, ties oldest first. Each works on the line amounts the previous ones left (stacked
   discounts compound). The first **non-stackable** (`is_stackable = false`) promotion that actually takes
   money off **stops the chain**; one that matches nothing does not.
   A promotion is skipped as if not running when `usage_count ≥ usage_limit_total`, when its `budget` is
   spent, or — once the phone is known (checkout) — when that phone/customer has used it
   `usage_limit_per_customer` times. A partly spent budget caps the discount at what is left.
2. **Coupon.** Applies to the post-promotion amounts of the lines in its scope **except lines discounted
   by a promotion with `allows_coupons = false`**. If every eligible line is blocked the coupon is
   rejected with `not_combinable`.
3. **Delivery.** `free_delivery` promotions the chain reached (before any stop) are candidates; the first
   whose threshold the eligible goods **after promotions and coupon** reach makes delivery 0 through
   Fulfilment's `FreeShippingPromotion` seam (`MarketingFreeShipping`, which also answers free-shipping
   coupons). It appears in `adjustments` as a `promotion` row with amount `0.00` and its name, so the cart
   and checkout can show "Promotion: Free delivery over ৳2000". The PDP estimator (no customer, no cart)
   honours only all-scope free-delivery promotions without a per-customer limit.

`PriceBreakdown` refuses to construct unless the itemised rows sum to the grand total and the lines sum
to the goods after discounts. Every order-level discount is spread with `Money::allocate` (no poisha lost
or invented); percentages round half away from zero to the poisha.

### Coupons and promotions — the explicit setting (open decision resolved per promotion)

`promotions.allows_coupons` — **default `true`**, which keeps the Phase 4 behaviour ("a coupon applies
after a non-stackable promotion"). The admin form shows the switch on every promotion. Set it off for a
promotion whose items must not take a coupon on top (typical for flash sales). It is per line: a coupon
still discounts the other lines in the cart. **Owner decision still open:** whether the default for new
*flash sales* should be `false`.

## Types and `rules`

Common: `scope` = `{applies_to: all|products|categories|brands, ids, exclude_product_ids,
exclude_category_ids, exclude_brand_ids}` (categories cover their subtree), `max_discount`.

| type | rules | effect |
|---|---|---|
| `discount` (and legacy `category_discount`) | `type: percent\|fixed`, `value` | percent of each eligible line, or `value` × quantity |
| `tiered` | `tiers: [{min_subtotal, type, value}]` | highest tier reached by the eligible amount, spread over the lines |
| `buy_x_get_y` | `buy`, `get`, `percent` (default 100) | per `buy + get` eligible units the `get` cheapest are discounted |
| `bundle` | `product_ids` (≥ 2), `type: percent\|fixed\|price`, `value` | once per complete set; `price` = the set sells for `value` |
| `free_delivery` | `min_subtotal` | delivery 0 when the eligible goods after discounts reach it |
| `flash_sale` | `flash_items` (below); legacy rows without items behave as `discount` | per-variant sale price for a limited allotment |

## Flash sales

A `flash_sale` must have both `starts_at` and `ends_at` (a hard window). Each `flash_sale_items` row is
one variant: `sale_price` (below the regular price), `allotment`, optional `per_order_limit`, and
`claimed`. The engine sells `min(quantity, allotment − claimed, per_order_limit)` units at the sale price;
further units stay at the regular price.

**Never oversold:** at checkout `PromotionUsages::record()` runs inside the order transaction, after the
stock reservation, and locks the applied promotions (`FOR UPDATE`, ascending id) and each flash sale's
items (ascending variant id, rule 9), re-checking total and per-phone limits, the budget and the
allotment. A shortfall throws `PromotionRejected` → **409 `price-changed`** (the customer re-prices).
Cancelling an order gives the units, usage count and budget back (`OrderCancelled` →
`ReverseOrderMarketingUsage`, which also reverses the coupon usage as that event's contract asks).
`tests/Feature/Marketing/Promotions/FlashSaleConcurrencyTest.php` (mysql group) races 8 checkouts for an
allotment of 3. An admin cannot lower an allotment below `claimed` or remove a line with claims.

Lock order at checkout: cart → stock levels → coupon → promotions → flash items. `EditOrder` releases the
order's promotion usage before re-pricing (so the order's own units count as available) — that takes the
promotion locks before the stock locks; a resulting deadlock is retried by both Actions' attempt loops.

**Storefront:** cards and the PDP carry `flash_sale` (`{promotion_id, name, name_bn, ends_at, variant_id,
sale_price, regular_price, discount_percent, remaining}`; the PDP adds `variants: {id: offer}`), and
`GET /api/v1/storefront/flash-sales` returns `{server_time, sales: [{…, products: [card]}], next}`. Display
only — a card cached by ISR may show a badge for a moment after a sale sold out; the cart is always right.
**ISR:** every admin save/deactivate and every start/end boundary (`toymart:promotions:boundaries`, every
minute) sends `products`, `flash-sale` and `product:{slug}` for the sale's products.

## Admin API — `/api/v1/admin`

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `/promotions` | marketing.view | `filter[search\|type\|status=scheduled\|running\|ended\|inactive]`, sorts `priority`, `starts_at`, `ends_at`, `created_at` |
| POST | `/promotions` | marketing.manage | body: `name`, `name_bn`, `description`, `type`, `priority`, `is_stackable`, `allows_coupons`, `starts_at`, `ends_at`, `is_active`, `usage_limit_total`, `usage_limit_per_customer`, `budget`, `rules`, `flash_items[{variant_id, sale_price, allotment, per_order_limit}]` |
| GET / PUT | `/promotions/{id}` | view / manage | PUT replaces the whole document |
| DELETE | `/promotions/{id}` | marketing.manage | deactivates; never deleted |
| GET | `/promotions/{id}/report` | marketing.view | orders, discount given, gross sales, **delivered** revenue, 50 latest uses (masked phones) |
| POST | `/coupons/generate` | marketing.manage | the coupon document without `code`, plus `count` (≤ 1000), `prefix`, `code_length` (6–16), `batch` → `{batch, count, codes}`; codes `PREFIX-XXXXXXXX` from an alphabet without 0/O/1/I/L |
| GET | `/coupons/{id}/report` | marketing.view | as the promotion report, plus `batch` totals |
| GET | `/coupons?filter[batch]=` | marketing.view | the codes of one batch |

Times without an offset are read as Dhaka time; responses are UTC (`Z`).
