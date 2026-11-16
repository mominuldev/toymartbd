# Gap analysis — new storefront designs vs current API and admin

> Audited 2026-11-16 against `main` (read-only). This is the backlog to implement **only when the owner approves**, on the `global-site` branch.
> Supersedes the earlier "multi-storefront" gap: the owner chose **themes on one store**, so `storefront_id` scoping is **not needed**.

Legend: ✅ supported · 🟡 partial · ❌ missing.

| Area | Status | Evidence (main) | Gap for the new designs |
|---|---|---|---|
| Storefront themes | ❌ | No theme concept. `store-info` returns one fixed brand (`StoreInfoController.php`). | The whole of [THEMES-SPEC.md](THEMES-SPEC.md) |
| Age range, variants, low stock | ✅ | `products.age_min_months/age_max_months`; attribute variants; `StockState::LOW_STOCK_THRESHOLD = 5` | Listing cards expose only `in_stock`, not the low-stock count. There are no size charts. |
| Listing filters | 🟡 | `ProductListingQuery` allowlist: category, brand, age, price, gender, rating, availability | **Colour, size and material** filters and facets. Product attributes have an `is_filterable` flag, but the listing never uses it to build filters. There is no `material` field. Age bands are toy-oriented. |
| Recommendations / bundles | 🟡 | `product_relations.type` includes upsell, cross_sell and bundle; admin `RelationsEditor.tsx`; `RecommendationPlacement` has `pdp_fbt` | The storefront PDP returns only `related`. There is no FBT bundle price before add-to-cart, no cart cross-sell endpoint, and **no `recommendation_events` migration** (it is designed in `DATABASE-DESIGN.md` but not built). |
| Flash sale | ✅ (minor) | `flash_sale_items` with allotment and claimed; `GET /storefront/flash-sales` | The storefront gets `remaining` only. It needs `allotment` or `claimed` to draw the "% claimed" meter. |
| Coupons / gifts | 🟡 | Cart coupon endpoints; `orders.is_gift`, `gift_message` | **Gift cards** missing. **Gift wrap** missing. Checkout does not accept a gift note. |
| Delivery | 🟡 | Delivery areas, quote and rates (threshold, progress, ETA); tracking by number + phone | **Express / same-day** option. |
| Payments | 🟡 | COD and SSLCommerz drivers | Native **bKash** and **Nagad** drivers (today only through SSLCommerz) |
| Accounts | 🟡 | SMS OTP, wishlist, addresses with landmark, order history | **Reorder / buy again** |
| Children | 🟡 | `customer_children` with birthday consent and a birthday coupon | **Size** (and optional gender) on a child |
| Reviews | 🟡 | Ratings, images JSON, verified purchase, distribution | Review **photo upload and display**. **Fit feedback** ("true to size"). |
| Loyalty club | ❌ | The code states no loyalty system exists | "Gallery Club" membership and perks (e.g. member free delivery) |
| Newsletter / club sign-up | ❌ | Opt-in exists only for signed-in accounts | Guest phone sign-up and a newsletter block |
| CMS blocks | 🟡 | `BlockType` covers hero, tiles, rails, flash sale, testimonials and more | Newsletter block, product-reviews block. Per-theme `supported_block_types` (themes spec §6). |
| "You save" totals | 🟡 | Cart returns promotion and coupon discounts | Compare-at (MRP − price) saving per line and in total |

## Suggested order (when approved)

1. **Themes core:** registry, the active-theme setting, the store-info additive field, the admin library, preview and activate. Everything else is styled through it.
2. Clothing filters (colour, size, material) and the low-stock count on listing cards.
3. Storefront recommendations: FBT with bundle price, cart cross-sell, and the `recommendation_events` migration.
4. Checkout extras: gift note and wrap, express delivery, then native bKash/Nagad drivers (decision pending in `DEVELOPMENT-ROADMAP.md` §8).
5. Review photos and fit feedback, child size, reorder.
6. Loyalty club and newsletter sign-up. These need a product decision on the perks first.
