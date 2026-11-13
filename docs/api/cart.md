# Cart, pricing and coupons (P4-CRT-01, P4-CRT-02, P4-CRT-04)

One implementation prices a cart and an order: `App\Domain\Cart\Services\PricingPipeline::price()`.
The storefront cart API calls it on every read and write; checkout (`PlaceOrder`, DATABASE-DESIGN §4.1
"re-price the cart from current data") calls it with the same input. No client adds, discounts or
rounds anything (CLAUDE.md rule 1) — the storefront renders the strings it is given.

## Pipeline

```
base → promotions (priority, stacking) → coupon → delivery → total
```

1. **Base.** Each *sellable* line (active variant of an active, published, not-deleted product) at the
   variant's **current** price. Anything else is listed in `unavailableVariantIds` and excluded from
   every figure.
2. **Promotions** (`Marketing\Services\PromotionEngine`). Running promotions (active, inside
   `starts_at`/`ends_at`) in **descending `priority`**, ties oldest first. Each works on the line amounts
   the previous ones left, so stacked discounts compound instead of double-counting. The first
   **non-stackable** promotion that actually takes money off stops the chain (one that matches nothing
   does not). A non-stackable promotion does **not** block coupons — see open decisions.
3. **Coupon** (`Marketing\Services\CouponEvaluator`) on the post-promotion amounts of the lines in its
   scope.
4. **Delivery** — `Fulfilment\Services\DeliveryChargeResolver::quote()`, the estimator's code path, with
   the **post-discount** goods value as the subtotal (so the free-delivery threshold is measured after
   discounts). A valid `free_shipping` coupon makes it 0 through §5.1 step 1 (`CouponFreeShipping`,
   bound over `NoFreeShippingPromotion`). Without a destination there is no delivery line.
5. **Total.**

Every order-level discount is split across lines with `Money::allocate` (no poisha lost or invented), so
each line carries its own discount for `order_items.discount`. `PriceBreakdown` refuses to construct
unless

```
subtotal − promotion_discount − coupon_discount + shipping_charge = grand_total
Σ line.total = subtotal − promotion_discount − coupon_discount
```

## Promotion `rules`

Common keys: `scope` = `{applies_to: all|products|categories|brands, ids: [...], exclude_product_ids,
exclude_category_ids, exclude_brand_ids}` (category ids cover the subtree; default all), `max_discount`
(decimal string, caps the promotion's total).

| type | keys | effect |
|---|---|---|
| `flash_sale`, `category_discount` | `type: percent\|fixed`, `value` | percent of each eligible line, or `value` × quantity off each eligible line |
| `tiered` | `tiers: [{min_subtotal, type, value}]` | highest tier whose `min_subtotal` the eligible amount reaches; spread over eligible lines |
| `buy_x_get_y` | `buy`, `get`, `percent` (default 100) | per `buy + get` eligible units, the `get` cheapest are discounted by `percent` |
| `bundle` | `product_ids` (≥ 2), `type`, `value` | once per complete set; fixed = `value` × sets, percent of the sets' value |

No admin API for promotions yet; rows are created by seeders or tinker.

## Coupons

Checks, in order: exists (code is case-insensitive, stored upper-case) → `is_active` → window →
`usage_limit_total` (`used_count`) → `usage_limit_per_customer` → `first_order_only` → scope/exclusions
(`not_applicable` when nothing in the cart is eligible) → `min_order_amount` (post-promotion goods).
`fixed` is capped at the eligible amount; `percent` at `max_discount`; `free_shipping` discounts no goods.

**Per-customer limit is keyed on the phone (E.164)**, plus the customer id when known, so it holds across
guest checkouts on one phone however it is typed (`017…`, `+880 17…`, Bengali digits). A cart has no
phone, so the cart can only check it at checkout. `CouponUsages::record()` re-checks total and per-phone
limits under `SELECT … FOR UPDATE` on the coupon row, inside the order transaction.

Rejection codes (`coupon.reason`): `not_found`, `inactive`, `not_started`, `expired`, `minimum_not_met`,
`usage_limit_reached`, `customer_limit_reached`, `not_applicable`, `first_order_only`. Messages in
`lang/{en,bn}/cart.php`.

## Storefront API — `/api/v1/storefront/cart`

Server token with `storefront:read` (the Next.js server's only credential). The cart token is a random
UUID returned as `token`; the Next.js server stores it in an **httpOnly cookie** and forwards it as the
**`X-Cart-Token`** header (never in a URL). An unknown, malformed, expired (30 days after the last
change), converted or merged token is a 404 `not-found` — drop the cookie and start again.

| Method | Path | Body / query | Notes |
|---|---|---|---|
| POST | `/cart` | — | 201, empty cart |
| GET | `/cart` | `?district_id=&upazila_id=` (optional) | destination adds `delivery` and `totals.shipping_charge` |
| POST | `/cart/items` | `{variant_id, quantity, recommendation_source?}` | no `X-Cart-Token` → creates the cart, **201**; existing line → quantity added. `recommendation_source` = the placement the add came from (`pdp_upsell`\|`pdp_cross_sell`\|`pdp_fbt`\|`pdp_age_progression`\|`cart_cross_sell`\|`confirmation_cross_sell`), stamped onto the order item at checkout (rule 25d) |
| PATCH | `/cart/items/{item}` | `{quantity}` 1–99 | raising is stock-checked; lowering always allowed |
| DELETE | `/cart/items/{item}` | — | |
| POST | `/cart/coupon` | `{code}` | replaces any coupon; rejection → 422 on `code` with the reason |
| DELETE | `/cart/coupon` | — | idempotent |

Stock: a quantity above `available = on_hand − reserved` (`StockAvailability`, read-only) is a 422
`validation-error` with `errors: [{field: "quantity", message: "Only 3 left in stock."}]` (Bangla with
`?locale=bn`: "স্টকে মাত্র 3টি আছে।"). Out of stock and unsellable variants are refused the same way.
Limits: 99 per line, 50 lines per cart. The cart check is advisory; checkout's reservation decides.

### Response (`Cart`)

```json
{
  "token": "2f9c8f8e-…", "expires_at": "2026-12-12T04:00:00Z", "item_count": 2,
  "items": [{
    "id": 12, "variant_id": 40,
    "product": {"id": 9, "name": "Remote Control Car", "name_bn": "রিমোট কন্ট্রোল গাড়ি", "slug": "remote-control-car"},
    "variant": {"sku": "RCC-RED", "name": "Red", "name_bn": "লাল",
                "attributes": [{"name": "Colour", "name_bn": "রং", "value": "Red", "value_bn": "লাল"}]},
    "image": {"url": "https://cdn…/160.webp", "alt": "Red remote control car", "alt_bn": "লাল রিমোট কন্ট্রোল গাড়ি"},
    "quantity": 2, "available_quantity": 5, "in_stock": true, "sellable": true,
    "unit_price": "1000.00", "added_unit_price": "1000.00", "price_changed": false,
    "subtotal": "2000.00", "discount": "200.00", "total": "1800.00"
  }],
  "coupon": {"code": "EID10", "applied": false, "reason": "minimum_not_met", "message": "Spend at least ৳2500.00 to use this coupon.", "discount": "0.00", "free_shipping": false},
  "adjustments": [
    {"type": "subtotal", "id": null, "label": null, "label_bn": null, "amount": "2000.00"},
    {"type": "promotion", "id": 3, "label": "Puja sale", "label_bn": "পূজার ছাড়", "amount": "-200.00"},
    {"type": "shipping", "id": null, "label": null, "label_bn": null, "amount": "130.00"}
  ],
  "totals": {"subtotal": "2000.00", "promotion_discount": "200.00", "coupon_discount": "0.00",
             "discount_total": "200.00", "shipping_charge": "130.00", "grand_total": "1930.00"},
  "delivery": { "…": "the DeliveryQuote object, as GET /delivery-quote" },
  "checkout_ready": true
}
```

`image` is the variant's own photo, else the product's primary, else its first (160px rendition; null
until one is processed) — the same choice the order item snapshots. `variant.attributes` are the
variant-defining values in attribute order. The storefront needs no per-product fetch to draw a line.

`adjustments` sum exactly to `totals.grand_total`. `checkout_ready` = at least one line, every line
sellable and in stock, the coupon (if any) applied, and a serviced destination given.

## Merge on login

`App\Domain\Cart\Actions\MergeGuestCart::handle(?string $guestToken, int $customerId): PricedCart` — call
from customer login with the forwarded guest token. A variant on both carts becomes one line with the
quantities added (capped at 99; stock is flagged, not enforced, so login never fails); the customer's
coupon wins; the guest cart closes (`merged_into_cart_id`). Carts are locked in ascending id order.

## Admin API — `/api/v1/admin/coupons`

`GET` (marketing.view; `filter[search]`, `filter[status]=active|inactive`; sorts `code`, `created_at`,
`ends_at`; cursor pagination) · `POST` · `GET /{coupon}` · `PUT /{coupon}` (whole document) · `DELETE
/{coupon}` = deactivate (marketing.manage). Body: `code`, `description`, `type`, `value`, `max_discount`,
`min_order_amount`, `usage_limit_total`, `usage_limit_per_customer`, `starts_at`, `ends_at`,
`applies_to`, `scope_ids`, `excludes{product_ids,category_ids,brand_ids}`, `first_order_only`,
`is_active`. Money is a decimal string.

## For checkout (`PlaceOrder`, batch 5)

Inside the order transaction:

```php
$breakdown = app(PricingPipeline::class)->price(new PricingInput(
    lines: $cart->lines(),               // or the submitted variant → qty map
    couponCode: $couponCode,
    district: $district, upazila: $upazila,
    phone: PhoneNumber::parse($shippingPhone), customerId: $customer?->id,
));
// refuse unless $breakdown->isCheckoutReady() (and lines in stock — the reservation decides)
// order_items: $line->unitPrice, ->quantity, ->discount(), ->total; orders: subtotal, promotionDiscount,
// couponDiscount, discountTotal(), shippingCharge, grandTotal; delivery quote: $breakdown->delivery
app(CouponUsages::class)->record($breakdown->coupon->coupon, $order->id, $phone, $breakdown->couponDiscount, $customer?->id);
// promotions.usage_count is not incremented by anything yet (no writer built)
```

After commit: `MarkCartConverted::handle($cartId, $orderId)` (from the order-placed event). On cancel:
`CouponUsages::reverseForOrder($orderId)` inside the cancel transaction. Bind
`Marketing\Contracts\CustomerOrderHistory` to a real implementation so `first_order_only` works.
