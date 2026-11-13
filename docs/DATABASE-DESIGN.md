# Toy Mart BD — Database Design

**Status:** Draft for review · **Date:** 2026-11-07 · **Engine:** MySQL 8.4 · InnoDB · `utf8mb4_0900_ai_ci`

---

## 1. Design Principles

1. **Variants are the unit of inventory.** Every product has at least one `product_variants` row, including "simple" products. SKU, barcode, prices and stock live only on the variant. This removes the nullable-`variant_id` branching that makes most e-commerce schemas painful to query and easy to get wrong.
2. **Ledgers are append-only.** `stock_movements` and `financial_transactions` are never updated or deleted. Current state (`stock_levels`, `order_financials`) is a cached projection that can always be rebuilt from the ledger. This is what makes "why is my stock wrong?" an answerable question.
3. **Snapshot everything that can change.** An order item stores the product name, SKU, selling price **and cost price** as they were at the moment of sale. Changing a product's cost tomorrow must not retroactively alter last month's profit.
4. **Phone number is the customer identity.** In Bangladesh most buyers never register. A normalised phone is unique on `customers`, and guest orders auto-attach to a customer record by phone. Lifetime value, repeat-purchase and risk history therefore work without forcing an account.
5. **Money is `DECIMAL(14,2)`.** Never float, never a bare integer whose unit you have to remember.
6. **Separate cash from revenue.** Revenue is recognised on delivery. Cash is recognised on courier remittance. The gap is a receivable. Conflating them is the single most common way a BD COD business misreads its own profitability.
7. **Store foreign vocabularies verbatim.** Raw courier and gateway payloads are kept alongside our normalised interpretation, so a mapping mistake is recoverable.
8. **Timestamps are UTC; report days are Dhaka days.** `APP_TIMEZONE=UTC` so Eloquent writes UTC, and a separate `APP_DISPLAY_TIMEZONE=Asia/Dhaka` drives display and report bucketing through the `DhakaDate` helper. These two settings are not interchangeable — setting `app.timezone` to `Asia/Dhaka` would make Eloquent *write* Dhaka-local datetimes and defeat UTC storage. Every "daily" figure buckets by Dhaka date: an order placed at 23:30 UTC belongs to the next Dhaka day. Getting this wrong is the classic source of wrong daily revenue.

### Conventions
Plural snake_case tables · `id` is `BIGINT UNSIGNED AUTO_INCREMENT` · every table has `created_at`/`updated_at` · user-facing identifiers (`orders.number`, `invoices.number`) are separate from `id` and never exposed as sequential-guessable where that leaks volume · enums as `VARCHAR` backed by PHP enums, not MySQL `ENUM` (so adding a value is not a table alter) · JSON columns only for genuinely schemaless data (raw payloads, flexible CMS block content, snapshots).

---

## 2. Entity Map

```
                     ┌──────────┐      ┌───────────────────┐
                     │  users   │──┬──▶│ model_has_roles   │──▶ roles ──▶ permissions
                     └──────────┘  │   └───────────────────┘
                          │        └──▶ activity_log (actor)
                          │
  ┌───────────┐     ┌─────▼──────┐    ┌──────────────┐    ┌──────────────────┐
  │ suppliers │────▶│ purchases  │───▶│purchase_items│───▶│ product_variants │
  └───────────┘     └────────────┘    └──────────────┘    └────────┬─────────┘
        │                  │                                       │
        │           ┌──────▼────────┐                     ┌─────────▼────────┐
        └──────────▶│supplier_pay.. │                     │  stock_levels    │
                    └───────────────┘                     └─────────┬────────┘
                                                                     │
  ┌──────────┐   ┌────────────┐   ┌──────────┐           ┌───────────▼────────┐
  │ brands   │──▶│  products  │◀──│categories│           │  stock_movements   │ (append-only)
  └──────────┘   └─────┬──────┘   └──────────┘           └───────────┬────────┘
                       │  ▲                                          │
             ┌─────────▼──┴──────────┐                               │
             │ product_variants      │◀──────────────────────────────┘
             │ product_images        │
             │ product_attributes    │
             │ variant_attr_values   │
             └───────────┬───────────┘
                         │
  ┌────────────┐   ┌─────▼──────┐   ┌─────────────┐   ┌──────────────────┐
  │   carts    │──▶│ cart_items │   │   orders    │──▶│   order_items    │
  └────────────┘   └────────────┘   └──────┬──────┘   └──────────────────┘
                                            │
        ┌───────────────┬───────────────┬───┴────────┬──────────────┬─────────────┐
        ▼               ▼               ▼            ▼              ▼             ▼
  ┌──────────┐  ┌──────────────┐  ┌──────────┐ ┌──────────┐  ┌───────────┐ ┌──────────────┐
  │ payments │  │  shipments   │  │ refunds  │ │ invoices │  │order_events│ │order_financ..│
  └────┬─────┘  └──────┬───────┘  └──────────┘ └──────────┘  └───────────┘ └──────────────┘
       │               │
       │        ┌──────▼──────────────────┐
       │        │courier_tracking_events  │
       │        │courier_providers        │
       │        │courier_locations        │
       │        │courier_settlements      │
       │        └─────────────────────────┘
       │
  ┌────▼──────────────────┐   ┌───────────────────────┐   ┌──────────────────┐
  │financial_transactions │   │      customers        │──▶│    addresses     │
  │ expenses              │   │ customer_risk_profiles│   │ divisions        │
  │ expense_categories    │   │ customer_notes        │   │ districts        │
  └───────────────────────┘   └───────────────────────┘   │ upazilas         │
                                                           └──────────────────┘
  ┌──────────┐ ┌──────────┐ ┌────────────┐  ┌───────────┐ ┌─────────────┐ ┌──────────────┐
  │ coupons  │ │promotions│ │ cms_blocks │  │ blog_posts│ │  seo_meta   │ │tracking_events│
  │coupon_us.│ │          │ │ cms_pages  │  │ media     │ │ slug_history│ │webhook_receipts│
  └──────────┘ └──────────┘ └────────────┘  └───────────┘ │ redirects   │ └──────────────┘
                                                           └─────────────┘
```

---

## 3. Core Tables

### 3.1 Catalog

**`products`**
`id` · `name` · `name_bn` NULL · `slug` UNIQUE (ASCII, ≤92, shared by both locales) · `type` (`simple`|`variable`) · `brand_id` FK NULL · `primary_category_id` FK · `short_description` · `short_description_bn` · `description` LONGTEXT · `description_bn` LONGTEXT · `specifications` JSON NULL · `age_min_months` SMALLINT NULL · `age_max_months` SMALLINT NULL · `gender` (`boys`|`girls`|`unisex`) NULL · `is_educational` BOOL · `safety_note` NULL · `safety_note_bn` NULL · `warranty_months` NULL · `status` (`draft`|`active`|`archived`) · `is_featured` BOOL · `rating_avg` DECIMAL(3,2) NULL · `rating_count` INT · `view_count` INT · `published_at` NULL · timestamps · `deleted_at`

> **As built (P4 rich-text description, 2026-11-12).** `description` / `description_bn` hold **HTML** from the admin's RichTextToolbar editor, sanitised on every save by `App\Domain\Catalog\Services\ProductDescription` (symfony/html-sanitizer allowlist: p, h2, h3, strong, em, ul, ol, li, blockquote, br; `a[href]` http/https/mailto with `rel="noopener nofollow"` off-site; `img[src, alt, width, height]` only from our own media — the `/m/…` URL or the media disk/CDN base). Text with no tags (written before the editor) is read as paragraphs split on blank lines; both Product resources serve it through the same filter, and the storefront filters it again before rendering. SEO and search read `ProductDescription::plainText()`. No column change.

> `age_min_months`/`age_max_months` in **months**, not years, because the 0–36-month range is where parents shop most precisely and "1–3 years" loses the distinction between a 6-month-old and a 30-month-old. No verified competitor offers age filtering; it is the single most valuable catalog attribute for this category.

**`product_variants`**
`id` · `product_id` FK CASCADE · `sku` UNIQUE · `barcode` UNIQUE NULL · `name` NULL (e.g. "Red / Large") · `name_bn` NULL · `cost_price` DECIMAL(14,2) · `price` DECIMAL(14,2) · `compare_at_price` NULL · `wholesale_price` NULL · `weight_grams` INT · `is_default` BOOL · `position` · `status` · timestamps
Index: `(product_id, status)`, `(price)`

> **Variant matrix (P4-CAT-03).** A variable product's variants are the cartesian product of the values selected for each variant-defining attribute. On edit, a combination that survives keeps its variant (id, SKU, prices); a new combination gets a new variant; a variant whose value was removed is **archived, never deleted**, and reactivated if the value comes back. Generated SKUs are `TM-{product id, 5 digits}` for a simple product and `TM-{product id}-{value code}-…` for a variable one (value code = the value slug, uppercased, A–Z/0–9 only, ≤ 8 chars, e.g. `TM-00042-RED-L`); any row may override its SKU, and renaming a product never changes one. Selling-price and cost changes on an existing variant write `audit_sensitive` rows.
>
> `cost_price` here is the **current** replacement cost used for margin display. Historical cost for profit always comes from `order_items.cost_price_snapshot` or, where exact costing is required, from `purchase_items` via FIFO. MVP uses weighted-average cost maintained on receipt; see §5.3.

**`product_attributes`** `id` · `name` · `name_bn` · `slug` UNIQUE · `type` (`select`|`color`|`text`) · `is_variant_defining` BOOL · `is_filterable` BOOL · `position`
**`product_attribute_values`** `id` · `attribute_id` FK · `value` · `value_bn` · `slug` · `color_hex` NULL · `position`
**`variant_attribute_values`** `variant_id` FK · `attribute_id` FK · `attribute_value_id` FK · PK `(variant_id, attribute_id)`

**`categories`** `id` · `parent_id` FK NULL · `name` · `name_bn` · `slug` UNIQUE · `description` · `image_path` · `icon` · `banner_path` · `position` · `is_featured` BOOL · `show_in_menu` BOOL · `status` · `_lft`/`_rgt`/`depth` (nested set, via `kalnoy/nestedset`) · timestamps · `deleted_at`

> Nested set rather than a recursive CTE: the mega menu and breadcrumbs read the tree on nearly every page and writes are rare. One indexed range query beats a recursive query per request.
>
> As built (P4-CAT-08): `depth` is **computed on read** (`withDepth()`), not stored — a stored copy would have to be rewritten for a whole subtree on every move. The tree is capped at three levels. `slug` is ≤ 91 characters (not 94 as for brands) so the revalidation tag `category:{slug}` fits the 100-character limit; `tree` is reserved. `description_bn` is added alongside `description` (rule 35). A category is archived, never deleted, and archiving is refused (409) while it has active subcategories.

**`category_product`** `category_id` · `product_id` · PK both — products appear in multiple categories; `products.primary_category_id` drives the canonical URL and breadcrumb to avoid duplicate-content issues.

**`brands`** `id` · `name` · `name_bn` NULL · `slug` UNIQUE (ASCII, ≤94, shared by both locales) · `logo_path` · `description` · `description_bn` NULL · `country` · `status` (`active`|`archived` — archived, never deleted, because products reference it) · `position`
**`product_images`** `id` · `product_id` · `variant_id` NULL · `path` NULL · `width` NULL · `height` NULL · `processing_status` (`processing`|`ready`|`failed`) · `rendition_widths` JSON NULL · `alt_text` · `alt_text_bn` · `position` · `is_primary`
> `path` is the rendition **base** on the media disk (`products/{product}/{ulid}`), not a file: renditions live at `{path}/{width}.webp|avif`, the sanitised master privately at `{path}/master.webp` (docs/api/media.md). A random id per upload makes every file immutable, so the CDN caches forever and a crop writes a new base. `width`/`height` are the master's (oriented, cropped) pixels so the storefront reserves the box with zero CLS. A duplicated product shares its source's bases; files are deleted only when no row references them. **Publish rule (P4-CAT-06):** a product cannot be saved `active` while any image lacks `alt_text` or `alt_text_bn`.
**`product_relations`** `id` · `product_id` · `related_product_id` · `type` (`related`|`upsell`|`cross_sell`|`bundle`|`age_progression`) · `position` · `reason_note` NULL · `is_auto` BOOL · `rule_id` FK NULL · `created_by` · timestamps
UNIQUE `(product_id, related_product_id, type)` · INDEX `(product_id, type, position)`

> `reason_note` exists because undocumented curation rots: six months on, nobody remembers why a doll was paired with a storage box, so nobody dares remove it. `is_auto` and `rule_id` separate hand-curated pairs from rule-generated ones, so regenerating the rules never destroys a human decision.
>
> **`age_progression` is its own type**, not a flavour of `upsell`. An upsell is a better version of the same toy; an age progression is the *next toy*, for a child who has outgrown this one. It is resolved from `age_min_months`/`age_max_months` plus the skill taxonomy, and it is the strongest repeat-purchase lever in this category — a parent who bought a 12–24m stacker is a near-certain buyer for 24–36m six months later. No audited competitor does this.

**`product_relation_rules`** (P2) `id` · `name` · `type` · `source_scope` JSON (category / brand / age band / skill) · `target_scope` JSON · `max_results` · `priority` · `is_active` · `last_run_at` · `generated_count`
> Lets the owner express "every Art & Craft product cross-sells refill packs" once instead of pairing 500 products by hand. Rule output writes `product_relations` rows with `is_auto = true`, so a regeneration replaces only generated rows.

**`recommendation_events`** `id` · `placement` (`pdp_upsell`|`pdp_cross_sell`|`pdp_fbt`|`pdp_age_progression`|`cart_cross_sell`|`confirmation_cross_sell`) · `event` (`impression`|`click`|`add_to_cart`|`purchased`) · `source_product_id` · `recommended_product_id` · `variant_id` NULL · `session_id` · `customer_id` NULL · `order_id` NULL · `occurred_at`
INDEX `(placement, occurred_at)`, `(source_product_id, recommended_product_id)`, `(order_id)`
> Append-only. This is what makes attribution a measurement rather than a belief: without impressions you cannot compute a conversion rate, and without a conversion rate a weak pairing never gets retired. Written from a queued job so it never slows a page.

**`order_items.recommendation_source`** NULL — added to `order_items`: the placement that produced this line, captured at add-to-cart.
> Attribution has to live on the order line, not only in an events table, because the P&L joins through `order_items`. This is how "upsell revenue last month" becomes a figure in the finance module instead of a guess.

> **Server-side guardrails, applied in the resolver and never left to the admin UI:** an out-of-stock, archived or unpublished product is filtered out; a product never recommends itself; an `upsell` target whose price is not higher than the source is rejected at write time; per-placement caps are enforced on read (2 upsell, 4 cross-sell, 1 FBT block). A recommendation block showing sold-out items damages trust more than an empty block, so the block hides itself rather than degrading.
**`reviews`** `id` · `product_id` · `customer_id` NULL · `order_item_id` NULL · `reviewer_name` · `rating` TINYINT · `title` · `body` · `images` JSON · `is_verified_purchase` BOOL · `status` (`pending`|`approved`|`rejected`) · `admin_reply` · timestamps

> `order_item_id` is how "verified purchase" is proven rather than asserted. Reviews default to `pending`; no competitor was verified to have moderated review collection, and fake-looking reviews actively reduce trust with parents.

### 3.2 Inventory

**`stock_levels`** `id` · `variant_id` FK UNIQUE (with `location_id`) · `location_id` FK (one row seeded for MVP) · `on_hand` INT · `reserved` INT · `incoming` INT · `reorder_point` INT · `reorder_quantity` INT · `avg_cost` DECIMAL(14,4) · `updated_at`
Derived: `available = on_hand - reserved`. UNIQUE `(variant_id, location_id)`.

**`stock_movements`** (append-only) `id` · `variant_id` FK · `location_id` FK · `type` · `quantity` INT (signed) · `balance_after` INT · `unit_cost` DECIMAL(14,4) NULL · `reference_type`/`reference_id` (polymorphic: Order, Purchase, StockAdjustment, Shipment, Refund) · `user_id` NULL · `note` · `created_at`
Index: `(variant_id, created_at)`, `(reference_type, reference_id)`, `(type, created_at)`

`type` values: `purchase_in` · `sale_out` · `sale_cancelled_in` · `customer_return_in` · `courier_return_in` · `adjustment_in` · `adjustment_out` · `damage_out` · `transfer_in` · `transfer_out` · `opening_balance`

> `balance_after` is denormalised deliberately: it makes the stock history screen readable without a window function, and it makes the reconciliation invariant trivially checkable.

> **As built (P4-CRT-05).** `stock_locations` (`code` UNIQUE · `name` · `name_bn` · `is_default` · `is_active`) holds one `main` row, inserted by its migration (it is the §9 `locations` table). `stock_levels` and `stock_movements` are written only by `App\Domain\Inventory\Services\StockLedger` (movements + `on_hand`) and `StockReservations` (`reserved`), inside the calling Action's transaction, locking levels `FOR UPDATE` in ascending `variant_id` and checking every line before writing any; no movement type may take `on_hand` below zero at MVP. Reserving writes no movement (`on_hand` is unchanged), so invariant §7.1 holds through reservations. The model refuses update/delete of a movement. Opening stock is one `opening_balance` movement per variant, written once (`reference_type = 'product'`); later changes are adjustments (Phase 5). Every level change fires `StockLevelChanged(variantId, productId)` after commit (search re-index, storefront revalidation). Read side: `StockAvailability` (`forVariants`, `inStockProductIds`), and Catalog's `AvailableStockQuery` contract for the listing's in-stock filter.

> **As built (P4 inventory overview, 2026-11-18).** `stock_locations` gains nullable `description` / `description_bn` (expand-only, `2026_11_18_100040`) — what the location is for, shown under its name on the inventory location cards ("Retail branch · POS + pickup"). `code` is the short label the "Other branches" column shows uppercased ("CTG 4 · SYL 0"). Opening stock is now recorded once per variant **per location** (`RecordOpeningStock(..., locationId:)`), so a branch that opens with stock on its shelves enters it as `opening_balance` movements there. Reorder point and quantity are set only through `App\Domain\Inventory\Actions\SetReorderLevels` (locks the level rows in ascending `variant_id`; writes no movement and never touches `on_hand`/`reserved`).
>
> **Overstock — owner decision pending (2026-11-18).** No max-stock column exists; overstock is computed server-side from the two planning figures already on `stock_levels`: a level is **overstock when `reorder_quantity > 0` and `available > reorder_point + 2 × reorder_quantity`** (`StockOverview::OVERSTOCK_REORDERS = 2`) — more than two full reorders above the point is cash sitting on the shelf. A level with no reorder quantity is never overstock. Chosen over a new `overstock_threshold` column because it needs no data entry and no migration; if the owner wants a per-variant ceiling instead, add a nullable `overstock_threshold` (expand-only) and let it override the rule where set.

**`stock_adjustments`** `id` · `location_id` · `reason` (`damage`|`loss`|`found`|`recount`|`expiry`|`correction`) · `note` · `user_id` · `approved_by` NULL · `status` · `created_at`
**`stock_adjustment_items`** `adjustment_id` · `variant_id` · `quantity_before` · `quantity_after` · `unit_cost` · `reason`

**`suppliers`** `id` · `name` · `contact_person` · `phone` · `alt_phone` · `email` · `address` · `district_id` · `tin` · `payment_terms_days` · `opening_balance` · `status` · `note`
**`purchases`** `id` · `number` UNIQUE · `supplier_id` FK · `location_id` · `status` (`draft`|`ordered`|`partially_received`|`received`|`cancelled`) · `order_date` · `expected_date` · `received_at` NULL · `subtotal` · `discount` · `shipping_cost` · `other_cost` · `tax` · `total` · `paid_amount` · `due_amount` · `supplier_invoice_no` · `note` · `created_by` · timestamps
**`purchase_items`** `id` · `purchase_id` · `variant_id` · `quantity_ordered` · `quantity_received` · `unit_cost` DECIMAL(14,4) · `landed_unit_cost` DECIMAL(14,4) · `discount` · `line_total`

> `landed_unit_cost` = `unit_cost` + the item's allocated share of `shipping_cost` + `other_cost`, apportioned by line value. Profit uses landed cost. Ignoring inbound freight is a quiet way to overstate margin by 5–15% on imported toys.

**`supplier_payments`** `id` · `supplier_id` · `purchase_id` NULL · `amount` · `method` · `reference` · `paid_at` · `note` · `created_by`

### 3.3 Customers & Addresses

**`customers`** `id` · `user_id` FK NULL (set when they register) · `name` · `phone` UNIQUE (normalised `01XXXXXXXXX`) · `phone_verified_at` NULL · `email` NULL · `email_verified_at` · `date_of_birth` NULL · `gender` NULL · `preferred_language` (`bn`|`en`) · `source` (`web`|`facebook`|`phone`|`walk_in`|`whatsapp`) · `tags` JSON · `first_order_at` · `last_order_at` · `orders_count` · `delivered_orders_count` · `total_spent` · `avg_order_value` · `status` · timestamps · `deleted_at`

> The aggregate columns are maintained by a queued listener on order state change. They are a projection, rebuildable from `orders`. They exist because the customer list must sort by lifetime value without scanning the order table.
>
> **As built (P4-ORD-01/10).** `phone` is stored **E.164** (`+8801XXXXXXXXX`, rule 30), not `01XXXXXXXXX`. `user_id` is a plain nullable id (storefront accounts arrive with P4-STO-11). Added `aggregates_refreshed_at`. Definitions: `orders_count` excludes cancelled orders; `delivered_orders_count` counts delivered, completed and partially_returned; `total_spent` = Σ(`grand_total` − `refunded_total`) over those; `avg_order_value` = total_spent ÷ delivered count; first/last over every order's `placed_at`. Customer reads orders through its `CustomerOrderHistory` contract (implemented by Ordering). Rebuild: `toymart:customers:rebuild-aggregates`. `addresses.phone` is E.164 too.

**`children_profiles`** `id` · `customer_id` · `name` NULL · `birth_date` · `gender` · `interests` JSON
> Optional, opt-in. Enables age-appropriate recommendations and birthday reminders — the highest-value retention feature available in this category and absent from every competitor checked. Must be clearly consented to; it is children's data.

**`addresses`** `id` · `customer_id` FK NULL · `label` · `recipient_name` · `phone` · `alt_phone` · `division_id` · `district_id` · `upazila_id` NULL · `area` · `address_line` · `landmark` · `postcode` NULL · `type` (`home`|`office`|`other`) · `is_default_shipping` · `is_default_billing` · `lat`/`lng` NULL · timestamps

**`divisions`** / **`districts`** / **`upazilas`** / **`unions`** / **`post_offices`** — static reference data: `name`, `name_bn`, parent FK, official P-code, and `is_inside_dhaka_metro` / `is_dhaka_subcity` BOOL on upazilas to drive the three-tier delivery-charge rule.

> **Source: `devfaysal/laravel-bangladesh-geocode`** (MIT, actively maintained) — 8 divisions / 64 districts / **495 upazilas** / 4,548 unions / 1,357 post offices, with English and Bangla names, real migrations and Eloquent models. Do **not** base this on `nuhil/bangladesh-geocode` despite its popularity: dormant since 2023, outdated spellings, 494 upazilas, and no postcodes. Reconcile once against the OCHA/BBS COD-AB boundary file, which resolves the 494/495/507 confusion — **495 upazilas plus 12 city corporations** — and store the official P-codes so a future reconciliation has a stable key. `data.gov.bd` has nothing usable (verified: it runs Drupal, not CKAN). **No official bulk postcode list exists**, so `postcode` is optional everywhere with lookup assistance, never a required field.

> **Phone validation** is `/^(?:\+?880|0)1[3-9]\d{8}$/`, stored E.164. Valid prefixes are **013–019 only**; 010/011/012 are rejected (011 was Citycell, defunct) along with the newer 06x IoT series. libphonenumber alone is insufficient — it is more permissive and accepts 011 — so the regex is layered on top of `propaganistas/laravel-phone` with `->country('BD')->type('mobile')`, and the rules are passed as an **array** because the pattern contains `|`. **The operator is never inferred from the prefix:** number portability has existed since 2018, and 016 is Robi/Cirkle, not Airtel — that brand was retired on 17 August 2026.

**`customer_risk_profiles`** `id` · `phone` UNIQUE (the key, not `customer_id`) · `customer_id` FK NULL · `total_orders` · `delivered_count` · `cancelled_count` · `returned_count` · `failed_delivery_count` · `refused_count` · `internal_score` TINYINT (0–100) · `score_breakdown` JSON · `score_computed_at` · `courier_data` JSON NULL · `courier_checked_at` NULL · `courier_delivered_count` NULL · `courier_cancelled_count` NULL · `status` (`normal`|`watch`|`blacklist`|`whitelist`) · `status_reason` · `status_set_by` · `status_set_at` · `manual_note` · timestamps

> Three deliberate separations. (a) Keyed on **phone**, so a repeat fake-order caller is recognised even as a new guest. (b) `internal_score` and `score_breakdown` are **our** computation and labelled as such in the UI. (c) `courier_*` columns hold **verified external data** with the timestamp of retrieval. The admin UI must never blend these into one number — staff decide whether to call a customer, and they need to know whether the evidence is ours or the courier's, and how old it is.

> **As built (P6 risk module, migrations `2026_11_25_600000`/`600100`).** The profile is a **projection rebuilt** from `orders` (and `shipments.delivery_attempts`) by `Risk\Actions\RefreshRiskProfile` on `OrderPlaced`, every `OrderStatusTransition` and `ShipmentStatusChanged` (queued, `reports`), so a replayed event cannot double count; staff columns are never touched by it. Counts: `delivered_count` = `delivered_at` set; `returned_count` = returned **after** delivery; `refused_count` = returned **without** delivery (§4.4 courier return); `failed_delivery_count` = failed attempts on our shipments (`shipments.status = failed` is a refused *booking*, not counted). Added `last_order_at`. Scoring (`Risk\Services\RiskScorer`, §5.2): each factor contributes `round(weight × strength)` ≤ its weight and the score is the plain sum, so `score_breakdown` (`[{code, weight, value, contribution}]`) always adds up; the cancellation rate counts only customer-caused reasons and both rates carry full weight from 3 orders. §5.2's "invalid prefix" is not a factor (rule 30 rejects such a phone). Weights/thresholds/first-order threshold live in `settings` key `risk.rules` (group `risk`; config/risk.php defaults; admin "Risk rules"). `OrderRiskAssessor` is the only hold decision: `high_risk_score` (not for `whitelist`), `first_order_above_threshold` (rule 33b, regardless of score or status), `cod_advance_required`, `blacklisted`, `watchlist` — all **hold**, none cancels; a person verifies (`VerifyHeldOrder`: verified_by/at, on_hold → pending) or cancels with a reason (`RejectHeldOrder`: verification `failed` + `CancelOrder`). New **`risk_verification_calls`** (`order_id`, `phone`, `outcome`, `note`, `user_id`, `created_at`; insert-only). The courier panel is composed in the Http layer from Fulfilment's `CourierHistoryLookup`, never stored into or blended with our score; the `courier_*` columns here stay unused until a provider is connected.

**`customer_notes`** `id` · `customer_id` · `user_id` · `body` · `is_pinned` · `created_at`

**`customer_otps`** (as built, Phase 4 batch 6) `id` · `phone_hash` (HMAC of the E.164 phone with the app key) · `code_hash` (HMAC) · `purpose` (`login`) · `attempts` · `expires_at` · `consumed_at` NULL · `ip_hash` NULL · timestamps — neither the code nor the phone is stored in plain; 5 wrong guesses burn the code.

**`wishlist_items`** (as built) `id` · `customer_id` FK · `product_id` FK · `created_at` — UNIQUE(`customer_id`, `product_id`); price and stock are read live, never copied.

### 3.4 Cart & Orders

**`carts`** `id` · `token` UUID UNIQUE · `customer_id` NULL · `coupon_id` NULL · `currency` · `subtotal` · `discount_total` · `shipping_total` · `grand_total` · `item_count` · `shipping_address_id` NULL · `notes` · `abandoned_at` NULL · `recovery_email_sent_at` NULL · `recovered_order_id` NULL · `expires_at` · timestamps
**`cart_items`** `id` · `cart_id` · `variant_id` · `quantity` · `unit_price` (snapshot at add) · `line_total` · `added_at`

> Server-side cart keyed by an opaque token in an httpOnly cookie. Totals are always the API's answer. `abandoned_at` plus `recovered_order_id` makes abandoned-cart recovery measurable rather than anecdotal.

> **As built (P4-CRT-01):** also `converted_at` + `order_id` (§4.1 "mark cart converted") and `merged_into_cart_id` (a guest cart merged into the customer's on login); `cart_items` is UNIQUE `(cart_id, variant_id)` — one line per variant, so a re-add or a merge raises the quantity. The token travels as the `X-Cart-Token` header from the Next.js server. `customer_id`, `order_id`, `recovered_order_id`, `shipping_address_id` have no FK until their tables exist. Pricing: `App\Domain\Cart\Services\PricingPipeline` — docs/api/cart.md. `cart_items.recommendation_source` (NULL, a `RecommendationPlacement` value, 2026-11-12) is the placement that added the line — sent as `recommendation_source` on `POST /cart/items`; the latest placement wins, a plain re-add keeps it, a guest merge keeps the customer's — and PlaceOrder copies it onto `order_items.recommendation_source` (rule 25d). `recommendation_events` and the rails that send a placement do not exist yet.

**`orders`**
`id` · `number` UNIQUE (`TMB-26-00001`) · `customer_id` FK · `cart_id` NULL · `status` · `payment_status` · `fulfillment_status` · `source` (`web`|`admin`|`phone`|`facebook`|`whatsapp`) · `channel_reference` NULL ·
money: `subtotal` · `discount_total` · `coupon_code` · `coupon_discount` · `promotion_discount` · `shipping_charge` · `shipping_discount` · `tax_total` · `grand_total` · `paid_total` · `due_total` · `refunded_total` ·
address snapshot: `shipping_name` · `shipping_phone` · `shipping_alt_phone` · `shipping_division` · `shipping_district` · `shipping_upazila` · `shipping_area` · `shipping_address` · `shipping_landmark` · `billing_*` (same shape) ·
`customer_note` · `internal_note` · `gift_message` NULL · `is_gift` BOOL ·
risk: `risk_score` NULL · `risk_level` (`low`|`medium`|`high`) · `risk_flags` JSON · `verification_status` (`not_required`|`pending`|`verified`|`failed`) · `verified_by` · `verified_at` ·
timeline: `placed_at` · `confirmed_at` · `packed_at` · `shipped_at` · `delivered_at` · `cancelled_at` · `returned_at` · `cancel_reason` · `return_reason` ·
`ip_address` · `user_agent` · `utm_source`/`utm_medium`/`utm_campaign` · `created_by` NULL · timestamps
Index: `(status, created_at)`, `(payment_status)`, `(customer_id, created_at)`, `(shipping_phone)`, `(risk_level, status)`, `(created_at)`

> **Three status columns, not one.** In a COD market an order can legitimately be `status=delivered`, `payment_status=paid`, and still have cash not remitted by the courier. A single status column forces either lying about one dimension or inventing composite states like `delivered_but_unpaid`. The three are: `status` (business workflow), `payment_status` (money owed/received), `fulfillment_status` (physical goods).

`status`: `pending` · `confirmed` · `processing` · `ready_to_ship` · `shipped` · `delivered` · `completed` · `cancelled` · `returned` · `partially_returned` · `on_hold`
`payment_status`: `unpaid` · `pending` · `partially_paid` · `paid` · `partially_refunded` · `refunded` · `failed`
`fulfillment_status`: `unfulfilled` · `partially_fulfilled` · `fulfilled` · `returned`

**`order_items`** `id` · `order_id` · `variant_id` FK NULL ON DELETE SET NULL · `product_id` NULL ·
snapshots: `product_name` · `product_name_bn` · `variant_name` · `sku` · `image_path` · `attributes` JSON ·
money: `unit_price` · `quantity` · `discount` · `tax` · `line_total` · **`cost_price_snapshot`** DECIMAL(14,4) ·
`quantity_shipped` · `quantity_returned` · `quantity_refunded` · timestamps

> `cost_price_snapshot` is the load-bearing column of the whole finance module. It is written once, at order creation, from the variant's weighted-average cost. It is never recalculated. Without it, editing a product's cost silently rewrites last quarter's profit.

**`order_events`** `id` · `order_id` · `type` · `from_status` · `to_status` · `description` · `meta` JSON · `user_id` NULL · `is_customer_visible` BOOL · `created_at`
> Serves both the internal activity log and the customer-facing tracking timeline from one table, with `is_customer_visible` deciding which rows are exposed.

**`order_financials`** (one row per order, projection) `order_id` PK · `revenue` · `cogs` · `gross_profit` · `discount_cost` · `courier_charge` · `courier_cod_fee` · `courier_return_charge` · `gateway_fee` · `packaging_cost` · `allocated_marketing_cost` · `refund_amount` · `return_loss` · `net_profit` · `margin_percent` · `computed_at`
> Recomputed by a queued job on every order state change, refund, and shipment event. Stored because order-wise and product-wise profitability screens must be fast, and because the invariant test compares it against the ledger.

> **As built (P4-ORD-01, batch 4).** `orders`: added `shipping_division_id`/`shipping_district_id`/`shipping_upazila_id` (geography ids beside the name snapshot, so courier mapping never joins on a name, rule 32), `shipping_postcode`, `payment_method` (denormalised from the first payment for the board), `currency`, `completed_at`, `cancel_note`; `cancel_reason` holds a `CancellationReason` value; `billing_*` are nullable (null = same as shipping); `cart_id` carries no FK (Cart owns carts); there is no `deleted_at` (rule 12). Order numbers come from **`order_number_sequences`** (`year` PK, `last_value`), locked `FOR UPDATE` per Dhaka year. `order_items`: §3.4's `attributes` is **`variant_attributes`** (`attributes` shadows Eloquent's internal property); `recommendation_source` added (rule 25d); `cost_price_snapshot` stays DECIMAL(14,4) and the model refuses to change it. `order_events`: added `field` (`status`|`payment_status`|`fulfillment_status`) so all three state machines share the timeline. `order_financials` is created but not yet computed (Finance, Phase 5). The three state machines and the PlaceOrder outline: `docs/api/orders.md`.

### 3.5 Payments, Refunds, Invoices

**`payments`** `id` · `order_id` · `method` (`cod`|`sslcommerz`|`bkash`|`nagad`|`card`|`bank_transfer`|`cash`) · `gateway` NULL · `amount` · `currency` · `status` (`pending`|`initiated`|`authorised`|`paid`|`failed`|`cancelled`|`expired`) · `transaction_id` NULL · `gateway_reference` NULL · `gateway_payload` JSON · `fee_amount` · `net_amount` · `paid_at` · `failure_reason` · `collected_by` NULL · `verified_at` NULL · timestamps
Index: `(order_id)`, `(transaction_id)` UNIQUE where not null, `(status, created_at)`

> `fee_amount` recorded per payment, not estimated at report time, so gateway charges are real numbers in the P&L.
>
> **As built (P4-PAY-02/03/04, migrations `2026_11_20_600000`, `600200`).** `payments` gains `expires_at` NULL (when an unpaid online attempt stops holding the order's reservation; NULL for COD), `review_reason` NULL and `review_flagged_at` NULL (the provider reported a mismatch or paid-after-close: never marked paid, the order is held). For SSLCommerz `transaction_id` = our `tran_id` (`{order number}-P{payment id}`, one per attempt) and `gateway_reference` = the session key, then `bank_tran_id` once paid. New **`payment_gateways`** `id` · `code` UNIQUE · `name` · `is_active` · `verified` (no production online payment while false — the payment counterpart of `courier_providers.verified`) · `sandbox` · `credentials` (encrypted) · `settings` JSON · `verified_at` · `position` · timestamps; `sslcommerz` is seeded inactive and unverified.

> **As built (P4-PAY-01).** Every method — COD included — opens at `pending` and moves only through `PaymentStatus::allowedTransitions()` (`docs/api/orders.md`); `payments.gateway` is the driver code. `refunds.status` is `requested`|`approved`|`processing`|`completed`|`failed`|`rejected`; `refund_items` has its own id and UNIQUE (`refund_id`, `order_item_id`).

**`refunds`** `id` · `order_id` · `payment_id` NULL · `amount` · `reason` · `type` (`full`|`partial`) · `method` · `status` · `gateway_reference` · `restock` BOOL · `requested_by` · `approved_by` NULL · `approved_at` · `processed_at` · `note` · timestamps
**`refund_items`** `refund_id` · `order_item_id` · `quantity` · `amount`

> **As built (P5 returns & refunds).** `refunds.shipping_amount` (the delivery-charge part of `amount`, refunded at most once per order). Two tables for the goods side (admin checklist §2.2 "RMA list, reason, inspection result, restock"): **`order_returns`** `id` · `number` (`RMA-YY-NNNNN`) · `order_id` · `status` (`requested`|`received`|`inspected`|`closed`|`rejected`) · `reason` (fixed list; `other` needs `reason_note`) · `reason_note` · `refund_shipping` · `refund_id` NULL · `requested_by` · `received_at` · `inspected_by` · `inspected_at` · `closed_at` · `note` · timestamps; **`order_return_items`** `order_return_id` · `order_item_id` · `quantity` · `quantity_resellable` · `quantity_damaged` (set at inspection; they sum to `quantity`). Returnable per line = `quantity − quantity_returned −` units held by open returns. `refunds.status` moves `requested → approved → (processing →) completed`, `requested → rejected`, `failed → processing|completed` (retry).

**`invoices`** `id` · `type` (`sales`|`purchase`) · `number` UNIQUE · `order_id` NULL · `purchase_id` NULL · `issued_to` JSON (snapshot) · `issued_by` JSON (company snapshot) · `subtotal` · `discount` · `shipping` · `tax` · `total` · `amount_in_words` · `issued_at` · `due_at` NULL · `pdf_path` NULL · `template` · `created_by`
> Company details are snapshotted onto the invoice. Reprinting a six-month-old invoice must show the address and BIN that were current then, not today's.

### 3.6 Fulfilment & Courier

**`courier_providers`** `id` · `code` (`pathao`|`steadfast`|...) · `name` · `is_active` · `is_default` · `credentials` JSON **encrypted** · `settings` JSON · `supports_bulk` · `supports_webhook` · `webhook_secret` encrypted · `base_charge_dhaka` · `base_charge_suburb` · `base_charge_outside` · `per_kg_charge` · `cod_fee_percent` · `return_charge` · `balance` · `balance_checked_at` · `position`
> Charges are stored and editable because courier rate cards change without notice, and they feed delivery-charge quoting and P&L. Credentials are encrypted at rest and only decrypted at call time.
>
> **As built (P4-CRT-03)**, ahead of the courier drivers because the delivery-charge resolver reads the rate card: also `name_bn` · `verified` BOOL (rule 33a) · `requires_location_mapping` BOOL · `included_weight_grams` INT (the weight covered by the base charge — 1000 Steadfast, 2000 Pathao; `per_kg_charge` applies per started kg above it). Seeded by `DeliverySeeder`: Steadfast (active, default) and Pathao (inactive), both unverified, with the design's placeholder ৳70 / ৳100 / ৳130.
> **As built (P6 courier settings, migration `2026_11_25_400000`):** `rate_card_updated_at` and `credentials_updated_at` TIMESTAMP NULL (the booking preview's "rate card edited …" provenance; `updated_at` also moves on an on/off toggle). Credential keys per provider are fixed in `Fulfilment\Support\CourierCredentialFields` (Pathao `client_id`, `client_secret`, `username`, `password`, `store_id`; Steadfast `api_key`, `secret_key`); the admin API never returns them (`set` + last-4 hint only) and a credential change writes an `audit_sensitive` `credential_changed` row naming the keys, never the values. Saving credentials never sets `verified`. Contract: `docs/api/courier-settings.md`.

**`courier_locations`** `id` · `provider_id` · `external_city_id` · `external_city_name` · `external_zone_id` · `external_zone_name` · `external_area_id` · `external_area_name` · `district_id` FK NULL · `upazila_id` FK NULL · `is_mapped` BOOL · `synced_at`
> **As built (P6, migration `2026_11_25_200000`).** Also `external_key` (`{city}:{zone}:{area|-}`, UNIQUE with `provider_id` — the upsert key, since MySQL treats NULLs in a composite unique as distinct) · `home_delivery_available` / `pickup_available` NULL · `mapped_by` FK users NULL · `mapped_at` · `is_active` (false once the provider stops returning the row; rows are never deleted, so mappings survive) · timestamps. One row per zone (`external_area_id` NULL) and one per area. `upazila_id` NULL with `district_id` set is a district-level mapping (a Dhaka-city address has no upazila). The sync (`toymart:couriers:sync-locations`, nightly 04:00 Dhaka) never writes `district_id`/`upazila_id`/`is_mapped`; only `PUT /admin/courier-locations/{provider}/locations/{id}/mapping` does. The resolver uses mapped rows only, never falls back from an upazila to its district, and treats several mapped zones as *ambiguous* (staff pick at booking). Observed on the Pathao sandbox 2026-11-13: **64 cities, 1,323 zones, 38,520 areas**, via the flat `/zones/list` and `/areas/list` (no pagination); no Nepal rows that day, but non-Bangladesh cities are filtered regardless.
> **This table exists because a courier's zone hierarchy does not match Bangladesh's postal geography.** Pathao's city/zone/area ids are its own namespace. Booking requires the courier's ids; our addresses use districts/upazilas. Rather than guessing, we sync the provider's location tree and maintain an explicit mapping, surfacing unmapped areas to the admin. A booking into an unmapped area is blocked with a clear message instead of being silently sent to the wrong zone.
>
> **This applies to Pathao only.** Verified: Steadfast has no city/zone/area model at all — `create_order` accepts only a free-text `recipient_address`. The mapping requirement is therefore a per-provider capability flag (`courier_providers.requires_location_mapping`), not a global precondition. Treating it as global would wrongly block every Steadfast booking.
>
> ⚠️ **The counts are a trap.** Pathao has roughly **64 cities and ~1,258 zones** against our **495 upazilas**. Because 64 ≈ 64, a naive join on district name looks plausible and then breaks on spelling variants (Cumilla/Comilla, Barishal/Barisal, Chattogram/Chittagong) and on zones that are landmark or neighbourhood clusters with **no counterpart in official geography and no shared key at all**. Pathao's own WooCommerce plugin sidesteps this by storing city/zone/area as separate order metadata chosen from a dedicated picker. We do the same: treat the provider's tree as a **parallel dataset**, mirror `/zones/list` and `/areas/list` nightly, map explicitly, and never guess.

**`courier_fraud_lookups`** `id` · `provider_id` · `phone` · `total_parcels` · `total_delivered` · `total_cancelled` · `total_fraud_reports` · `delivery_ratio` DECIMAL(5,4) NULL · `cancellation_ratio` NULL · `raw` JSON · `source` (`official_api`) · `fetched_at` · INDEX `(phone, fetched_at)`
> Populated only from a provider's **officially published** endpoint — at present Steadfast's `GET /fraud_check/{phone}`. Results are cached with their retrieval timestamp and surfaced as verified external data, never blended into our own score. We deliberately do **not** implement the market-standard approach of storing the merchant's courier-portal password to scrape undocumented internal endpoints; see `SYSTEM-ARCHITECTURE.md` §8. Note also that Steadfast's newer `/fraud_check/score/{phone}` currently returns `score` and `level` as `null` with `scoring_disabled: true`, so the ratios are consumed and the score is computed by us.

**`shipments`** `id` · `order_id` · `provider_id` · `number` · `consignment_id` NULL · `tracking_code` NULL · `tracking_url` NULL · `status` · `provider_status_raw` · `weight_grams` · `item_count` · `cod_amount` · `declared_value` · `charge` · `cod_fee` · `return_charge` · `total_cost` · `pickup_requested_at` · `booked_at` · `picked_up_at` · `delivered_at` · `returned_at` · `delivery_attempts` TINYINT · `failure_reason` · `recipient_signature` NULL · `label_path` · `booking_payload` JSON · `booking_response` JSON · `error_message` · `booked_by` · timestamps
Index: `(order_id)`, `(consignment_id)`, `(provider_id, status)`, `(status, booked_at)`

> `tracking_code` is `VARCHAR(32)` and is never length-validated: Steadfast returns variable-length hex between 8 and 15 characters. `consignment_id` is stored as a string even though Steadfast returns an integer, because provider id formats differ and we never do arithmetic on one.
>
> **Bulk booking cannot return consignment ids.** Verified: Pathao's bulk endpoint responds `202` with no ids, and Steadfast's accepts up to 500 orders with a JSON-*string* body. A bulk-booked shipment therefore sits at `status=submitted` until a webhook or the reconciliation poll supplies its id. The admin UI must show "submitted", not "booked" — a staff member who believes a parcel is booked when it is not will not chase it.
>
> **As built (P6 courier core, migrations `2026_11_25_100000`/`100100`).** `number` is `SHP-YY-NNNNNN` (per Dhaka year, `shipment_number_sequences` locked `FOR UPDATE`) and is also the merchant reference sent to the courier. Added: `courier_location_id` (no FK; the explicitly chosen/mapped `courier_locations` row, rule 32), `dispatched_at` (the order was marked shipped for this shipment — stock consumed, `courier_charge` written), `cod_collected`, `cancelled_at`, `booking_attempts`, `last_attempt_at`, `failure_code` (machine-readable: `unverified_provider`, `inactive_provider`, `unmapped_location`, `ambiguous_location`, `provider_refused`, `transport`, `order_not_shippable`, `completion_failed`), `note`, `requested_by`. One open shipment per order (anything but `failed`/`cancelled`). Fulfilment never writes orders, stock or the ledger: `ShipmentBooked` / `ShipmentDelivered` / `ShipmentReturned` are handled synchronously by Ordering inside the shipment transaction (shared `OrderDispatcher` with manual dispatch; `MarkOrderDelivered`; `RecordCourierReturn`) and after commit by Finance (`courier_charge` at booking — out, recognised, cash; `courier_cod_fee` at delivery; `courier_return_charge` plus an `order_reversal` mirror only when revenue had been recognised), all on source `shipment`. Partial delivery is recorded on the shipment and flagged on the order as a note; settling the delivered lines is a staff step because providers do not report which lines were delivered. Flow and endpoints: `docs/api/shipments.md`.
>
> **Partial delivery is a real state.** Both providers support delivering some items of a multi-item parcel. `partially_delivered` settles COD for the delivered lines, restocks the rest via `courier_return_in`, and sets the order to `partially_returned`. AppByte has no such state; orders like these are exactly where money quietly goes missing.

**`courier_tracking_events`** `id` · `shipment_id` · `provider_id` · `normalised_status` · `provider_status` · `message` · `location` · `occurred_at` · `received_at` · `payload` JSON · `source` (`webhook`|`poll`|`manual`)
Index: `(shipment_id, occurred_at)`, UNIQUE `(shipment_id, provider_status, occurred_at)` to make ingestion idempotent.

> **As built (P6 status ingestion, migration `2026_11_25_500000`).** Adds `from_status`; `source` also takes `booking`; no `updated_at` (append-only, the model refuses updates/deletes). The idempotency key is UNIQUE `(shipment_id, normalised_status, occurred_at)` — the normalised move, since one provider status string can recur. Written by `RecordCourierTrackingEvent` on every `ShipmentStatusChanged` (not pending/failed), so manual and courier moves share one history; `occurred_at` is the shipment's timestamp column for that status where it has one, else the move's time. Webhooks reuse `webhook_receipts` with provider `courier:{code}`. See `docs/api/courier-tracking.md`.

**`courier_settlements`** `id` · `provider_id` · `reference` · `period_start` · `period_end` · `cod_collected` · `delivery_charges` · `cod_fees` · `return_charges` · `adjustments` · `net_received` · `received_at` · `status` (`expected`|`received`|`disputed`|`reconciled`) · `statement_path` · `note`
**`courier_settlement_items`** `settlement_id` · `shipment_id` · `order_id` · `cod_amount` · `charges` · `net` · `is_matched` BOOL · `variance`
> This is how COD receivables are closed out. Delivered COD orders create a receivable; a settlement matches against them and surfaces variances. Without this table, "the courier owes us money" is an untracked guess — and it is usually the largest number on the balance sheet for a BD COD business.

> **As built (P6 COD settlement, migrations `2026_11_25_600200`/`600300`, Finance domain).** Import (`ImportCourierSettlement`) from a CSV/XLSX portal export or typed lines; UNIQUE(`provider_id`, `reference`). Each line is matched to `shipments` by (provider, consignment id) and compared with the shipment (`cod_collected` else `cod_amount`, less its charge and COD fee); items add `line_no`, `consignment_id`, `cod_fee`, `return_charge`, `expected_cod`, `expected_net`, `variance_type` (none · short · over · missing · unknown_consignment · not_delivered · already_settled · duplicate_line · exceeds_receivable), `review_status` (pending · accepted · excluded, with who/when/note) and `booked_at`; header adds `source`, `variance_count`, imported/confirmed by/at. With a period, delivered COD shipments the statement omits become `missing` lines. Nothing is adjusted automatically: only a `short` line can be accepted; every other variance can only be excluded. **Confirm** (`ConfirmCourierSettlement`) is refused while a variance is undecided, marks bookable lines and dispatches `CourierSettlementReconciled`; `Finance\Listeners\Settlement\BookCourierSettlement` writes per line `cod_received` = the **gross** collected (cash, not revenue; source `courier_settlement_item`, listed in `OrderLedgerSources`) and `courier_charge` / `courier_cod_fee` under the **same key the courier listeners use** (`shipment`, shipment id), so whichever is first wins and the other writes nothing. Statuses stored: `received`, `reconciled` (`disputed` is derived: unresolved variance lines). The manual "Record COD received" stays for one-offs; a line above the order's outstanding receivable is flagged `exceeds_receivable`. RESEARCH GAP: the couriers' portal export column names are unverified — the reader takes our template columns plus aliases and refuses a file without consignment and COD columns. Steadfast's documented `GET /payments` / `/payments/{id}` could feed the same import but needs a merchant account (not built).

### 3.7 Finance

**`expense_categories`** `id` · `name` · `type` (`cogs`|`operating`|`marketing`|`logistics`|`other`) · `is_fixed` BOOL · `parent_id` NULL
**`expenses`** `id` · `category_id` · `amount` · `expense_date` · `payment_method` · `reference` · `vendor` · `description` · `attachment_path` · `is_recurring` · `recurrence` · `order_id` NULL · `shipment_id` NULL · `created_by` · `approved_by` NULL
**`financial_transactions`** (append-only ledger) `id` · `type` · `direction` (`in`|`out`) · `amount` · `occurred_on` DATE (Dhaka date) · `source_type`/`source_id` polymorphic · `category_id` NULL · `description` · `is_cash_movement` BOOL · `is_recognised_revenue` BOOL · `created_at`
Index: `(occurred_on, type)`, `(source_type, source_id)`, `(type, occurred_on)`

`type` values: `sale_revenue` · `cogs` · `discount_given` · `shipping_revenue` · `courier_charge` · `courier_cod_fee` · `courier_return_charge` · `gateway_fee` · `packaging` · `marketing` · `salary` · `rent` · `utility` · `refund` · `inventory_write_off` · `supplier_payment` · `cod_received` · `other_income` · `other_expense`

> **Two booleans carry the whole revenue-versus-cash distinction.** `is_recognised_revenue` marks entries that belong in the P&L. `is_cash_movement` marks entries that moved actual money. A delivered COD order writes `sale_revenue` (recognised, not cash); the later courier remittance writes `cod_received` (cash, not revenue). Receivables = recognised minus received. This is why the P&L and the cash position can both be correct and different.

**`daily_financial_summaries`** `summary_date` PK · all the above aggregated, plus `orders_placed`, `orders_delivered`, `orders_cancelled`, `orders_returned`, `new_customers`, `units_sold`, `cod_receivable_closing`
> Nightly rollup at 01:00 Dhaka. Dashboards read this, not the ledger. A 12-month revenue chart must not scan a million ledger rows.

> **As built (P5 finance, migrations `2026_11_20_300000`–`300400`).** The only writer of `financial_transactions` is `App\Domain\Finance\Contracts\Ledger::record(LedgerEntry ...)` (bound to `EloquentLedger`); the model throws on update/delete. `amount` is always ≥ 0, `direction` carries the sign. **UNIQUE `(source_type, source_id, type)`** is the idempotency key (a replayed event writes nothing), so a correction or a second entry of one type needs its own source (`order_reversal`, `expense_void`, `expense_payment`…); it also serves the `(source_type, source_id)` index. `source_type` is a short alias (`order`, `expense`, `manual_cod_receipt`…), never a class name. Delivery (`OrderDelivered` → `RecognizeOrderRevenue`, queued after commit) writes `sale_revenue` = subtotal + tax_total, `discount_given` = discount_total, `shipping_revenue`, and `cogs` = Σ `cost_price_snapshot × quantity`, all recognised and none cash, dated the Dhaka day of `delivered_at`; §4.3's `courier_cod_fee`/`packaging` rows belong to the fulfilment/courier listeners. `expense_categories` adds `name_bn`, `ledger_type` (which ledger type its expenses post as; null derives from `type`) and `is_active` (DELETE deactivates). `expenses` adds `status` (`pending`→`approved`|`rejected`; `approved`→`void`), `approved_at`, `rejected_reason`, `paid_on` (null `payment_method` = unpaid), `attachment_name`/`_mime`/`_size` (receipt on the private originals disk, `finance/receipts/`), `recurrence_parent_id`+`recurrence_period` UNIQUE (generated monthly copies, pending approval), void trail; `shipment_id` has no FK yet. Only approval (`out`, recognised, cash iff paid) and void (the mirror `in`) touch the ledger; paying an approved expense later writes a cash-only `expense_payment`. The creator cannot approve their own expense (super admin excepted). New **`manual_cod_receipts`** (`order_id`, `amount`, `received_on`, `reference`, `note`, `recorded_by`; insert-only) back a "record COD received" action until Phase 6 settlement: each writes `cod_received` (cash, not revenue). `order_financials` is a projection rebuilt from recognised entries of every order-scoped source listed in `Finance\Services\OrderLedgerSources` (revenue = sale_revenue − discount_given; net_profit = Σ recognised in − out; margin = net ÷ revenue); invariant §7.2 is `php artisan toymart:finance:verify` (exits 1 on drift, never heals; `--rebuild` repairs after the cause is fixed), scheduled 01:30 Dhaka. **`daily_financial_summaries`** has one DECIMAL column per ledger type (named exactly as the type, in its natural direction), `recognised_in`/`recognised_out`/`net_profit`, `cash_in`/`cash_out`/`net_cash`, the §3.7 counts, `cod_receivable_closing` and `computed_at`; `toymart:finance:rollup --from= --to=` rebuilds any range. Recurring expenses: `toymart:finance:recurring-expenses` on the 1st, 00:30 Dhaka.

### 3.8 Marketing

**`coupons`** `id` · `code` UNIQUE · `type` (`fixed`|`percent`|`free_shipping`) · `value` · `max_discount` NULL · `min_order_amount` · `usage_limit_total` · `usage_limit_per_customer` · `used_count` · `starts_at` · `ends_at` · `applies_to` (`all`|`categories`|`products`|`brands`) · `conditions` JSON · `excludes` JSON · `first_order_only` BOOL · `customer_segment_id` NULL · `is_active` · `created_by`
**`coupon_usages`** `coupon_id` · `order_id` · `customer_id` · `phone` · `discount_amount` · `used_at` — `phone` is stored so per-customer limits survive guest checkout.
**`promotions`** `id` · `name` · `type` (`flash_sale`|`bundle`|`buy_x_get_y`|`category_discount`|`tiered`) · `priority` · `is_stackable` BOOL · `rules` JSON · `starts_at` · `ends_at` · `is_active` · `banner_path` · `usage_count`
> `priority` and `is_stackable` exist because overlapping promotions are the main source of "the price on the page was wrong" incidents. The pricing pipeline applies promotions in priority order and stops at the first non-stackable match; the resolved breakdown is written to the order so any later dispute is answerable.

> **As built (P4-CRT-02/04):** `promotions.name_bn` added (shown in the cart breakdown); `coupons.description` added; `coupons.code` stored upper-case; `coupons.conditions` = `{ids: [...]}` for `applies_to`, `excludes` = `{product_ids, category_ids, brand_ids}` (category ids cover the subtree). Priority is **descending** (higher first, ties oldest first); a non-stackable promotion stops the chain only if it actually discounted something. `coupon_usages` has `id`/timestamps, UNIQUE `(coupon_id, order_id)`, and no FK on `order_id`/`customer_id` until those tables exist. `customer_segment_id` is stored but not evaluated (segments are Phase 5). Rules shapes: docs/api/cart.md.

**`customer_segments`** `id` · `name` · `type` (`dynamic`|`static`) · `rules` JSON · `customer_count` · `last_computed_at`
**`campaigns`** `id` · `name` · `channel` (`sms`|`whatsapp`|`email`) · `segment_id` · `template_id` · `scheduled_at` · `status` · `sent_count` · `delivered_count` · `failed_count` · `cost`
**`notification_logs`** `id` · `channel` · `recipient` · `template` · `payload` JSON · `status` · `provider_message_id` · `cost` · `error` · `notifiable_type`/`notifiable_id` · `sent_at`
> Per-message cost logged so SMS/WhatsApp spend lands in the P&L as a real figure.

> **As built (P4-NOT-01..03, P4-I18N-03):** `notification_logs` adds `category` (`security`|`order_updates`|`marketing`), `locale`, `recipient_hash` (SHA-256 of the E.164 phone / lower-cased email), `provider`, `segments`, `encoding`, `attempts`, `fallback_of_id` (SMS fallback of a failed WhatsApp send), `queued_at`/`failed_at`; `recipient` is stored **masked** (rule 20) and the full address is re-read from the order at send time. `status` = `queued`|`sent`|`failed`|`dead_lettered`|`skipped` (skipped = opted out, kept as proof). `notifiable_type` is the alias `order`. New **`notification_templates`** (`key`+`channel` UNIQUE, `body_en`/`body_bn`, email `subject_*`/`preheader_*`, WhatsApp `provider_template`, `is_active`, server-measured `chars_*`/`segments_*`/`encoding_*`) — the variable allowlist lives in code (`TemplateCatalog`). New **`notification_preferences`** (`customer_id`, `channel`, `category`, `enabled`, `source`; no row = default: order updates on, marketing off; `security` cannot be opted out). No FKs to `orders`/`customers` (earlier migration timestamps).

### 3.9 Content & SEO

**`cms_pages`** `id` · `slug` UNIQUE · `title` · `title_bn` · `content` LONGTEXT · `content_bn` · `template` · `status` · `published_at` · `author_id`
**`cms_blocks`** `id` · `key` UNIQUE · `type` (`hero_slider`|`category_grid`|`product_carousel`|`banner_row`|`usp_strip`|`testimonials`|`rich_text`|`video`|`countdown`) · `placement` (`home`|`category`|`product`|`footer`) · `title` · `title_bn` · `content` JSON · `position` · `is_active` · `starts_at` NULL · `ends_at` NULL
> `content` as JSON with a per-type schema validated server-side. The homepage is an ordered list of active blocks, so the owner reorders or schedules a Ramadan hero without a developer — the explicit CMS requirement.

**`blog_posts`** `id` · `slug` UNIQUE · `title` · `title_bn` · `excerpt` · `content` LONGTEXT · `cover_image` · `author_id` · `category_id` · `reading_minutes` · `status` · `published_at` · `view_count` · `is_featured`
**`blog_categories`**, **`blog_post_tag`**, **`tags`** — standard.
**`media`** `id` · `path` · `disk` · `mime` · `size` · `width` · `height` · `alt_text` · `alt_text_bn` · `folder` · `uploaded_by` · `variants` JSON (generated sizes/formats)

**`seo_meta`** `id` · `seoable_type`/`seoable_id` · `title` · `title_bn` · `description` · `description_bn` · `canonical_url` NULL · `robots` (`index,follow` default) · `og_title` · `og_description` · `og_image` · `twitter_card` · `schema_override` JSON NULL · `focus_keyword` · UNIQUE `(seoable_type, seoable_id)`
**`slug_history`** `id` · `sluggable_type`/`sluggable_id` · `old_slug` · `new_slug` · `created_at` · INDEX `(sluggable_type, old_slug)`
> Every slug change writes a history row, and a middleware 301s any request for an old slug to the current URL automatically. This preserves accumulated ranking whenever a product is renamed — a permanent, invisible SEO advantage over competitors whose renames produce dead URLs.

> **As built (P4 batch 5, migrations `2026_11_16_400300`/`400400`).** Both tables exist for products only so far (`seoable_type` / `sluggable_type` = `product`, a short morph key, never a class name). `seo_meta` adds `sitemap_exclude` BOOL (default false) and sizes `title`/`og_title` 191, `description`/`og_description` 500, URLs 2048, `focus_keyword` 120; `robots` ∈ `index,follow`·`noindex,follow`·`index,nofollow`·`noindex,nofollow`, `twitter_card` ∈ `summary_large_image`·`summary`. It is written only with the product document, in the same transaction (`App\Domain\Seo\Actions\SaveProductWithSeo`). `slug_history` adds `user_id` NULL and INDEX `(sluggable_type, sluggable_id)`; it is insert-only except that (a) when a retired slug becomes live again its row is deleted so the live URL wins and (b) earlier rows are re-pointed to the newest slug so a redirect is always one hop. A row is written only when the old URL was **live** (active and published) — a draft's URL never was — and the admin must send `acknowledge_slug_redirect: true` to change a live slug. **Not built yet (Phase 7):** the storefront 301 middleware that reads `slug_history`, the storefront `<head>` reading `seo_meta`, and the SEO → Redirects screen.
>
> Product columns added in the same batch: `channel_online_store`/`channel_pos` BOOL default true, `channel_social`/`channel_google_merchant` BOOL default false (sales-channel flags, display and feed gating only), `vat_rate` DECIMAL(5,2) default 0 (VAT included in the selling price, used by the server's margin), and `bsti_certificate_no` VARCHAR(64) · `bsti_expires_on` DATE · `bsti_certificate_path`/`_name`/`_size` (the PDF on the private originals disk, content-sniffed on upload, served only as an attachment).

> **As built — media library (Phase 4, migrations `2026_11_17_100000`–`100300`, `docs/api/media-library.md`).** `media` replaces the sketch above: `id` · `kind` (`image`|`document`) · `folder_id` NULL → `media_folders` (null on folder delete) · `filename` (sanitised upload name) · `caption` · `alt_text` · `alt_text_bn` · `base_path` UNIQUE (the immutable file-set directory; master or PDF on the private originals disk, renditions `{base}/{w}.{webp|avif}` on the public disk) · `mime` (stored: `image/webp`|`application/pdf`) · `original_mime` (sniffed upload type) · `processing_status` · `width` · `height` · `original_size_bytes` · `master_bytes` · `renditions_bytes` · `size_bytes` (served: largest WebP, or the PDF) · `renditions` JSON `[{w,f,b}]` (replaces `variants`) · `origin` (`library`|`product`) · `uploaded_by` NULL → users · timestamps. **No soft delete**: a delete removes the row, then the files after commit. Replace moves the row to a new `base_path`; paths never change in place. The `disk` column was dropped (the disks are config). **`media_folders`** `id` · `name` · `name_key` UNIQUE (lower-cased; case-insensitive uniqueness) · `system_key` UNIQUE NULL (`products`, `category_banners`, `blog`, `pages` — inserted by the migration and `MediaFoldersSeeder`, never renamed or deleted) · `position` · timestamps. Flat, one folder per file at most. **`product_images.media_id`** NULL → `media` ON DELETE RESTRICT (expand only): for a linked row the media is the source of truth for file and alt text; the old `path`/`width`/`height`/`processing_status`/`rendition_widths`/`alt_text`/`alt_text_bn` columns are a write-through mirror, kept for rollback and dropped in a later contract step. Usages are resolved by `MediaUsages` over tagged `MediaUsageProvider`s (product images today; `seo_meta.og_image` stores a URL, and brand/category images store paths, so neither is a usage yet).

**`redirects`** `id` · `from_path` UNIQUE · `to_path` · `status_code` (301|302) · `hit_count` · `last_hit_at` · `is_active` · `note`

### 3.10 System

**`tracking_events`** `id` · `event_id` UUID (shared with the browser event for deduplication) · `event_name` · `source` (`browser`|`server`) · `customer_id` NULL · `session_id` · `order_id` NULL · `value` · `currency` · `payload` JSON · `consent_state` JSON · `ga4_status` · `ga4_response` · `meta_status` · `meta_response` · `dispatched_at` · `created_at`
**`webhook_receipts`** `id` · `provider` · `external_event_id` · `signature_valid` BOOL · `payload` JSON · `headers` JSON · `processed_at` NULL · `result` · `error` · UNIQUE `(provider, external_event_id)`

> **As built (P4-PAY-03, migration `2026_11_20_600100`).** Adds `source` (`webhook`|`return` — the browser return carries the same signed POST) and timestamps. Only **signature-verified** payloads are written (rule 19: verify before writing anything), so `signature_valid` is always true today. `headers` is an allowlist (user-agent, content-type, x-request-id). `result`: `applied`|`unchanged`|`flagged`|`ignored`|`unknown_payment`, or `deferred` with `processed_at` NULL when the provider could not be re-queried (retried). SSLCommerz event id = `{tran_id}:{status}:{val_id}`.
**`settings`** `key` PK · `value` JSON · `group` · `is_encrypted` BOOL · `updated_by` · `created_at` · `updated_at` (both timestamps, per the table conventions)
**`activity_log`** — `spatie/laravel-activitylog` schema.
**`audit_sensitive`** `id` · `user_id` · `action` · `model_type`/`model_id` · `before` JSON · `after` JSON · `ip` · `user_agent` · `created_at` — immutable, insert-only, no delete grant for the application DB user. Covers price, cost, refund, role and credential changes.
**`users`**, **`roles`**, **`permissions`** — `spatie/laravel-permission`.
Roles at launch: `super_admin` · `manager` · `order_operator` · `inventory_staff` · `accountant` · `content_editor` · `viewer`.

---

## 4. Transaction Boundaries

Each of these runs inside one `DB::transaction`. Nothing in the list may be split across requests.

### 4.1 Place order
```
BEGIN
  re-price the cart from current data          (never trust client totals)
  re-validate coupon and promotion eligibility
  SELECT … FOR UPDATE on stock_levels for every variant, ORDERED BY variant_id
     ↳ ordering prevents deadlock between two concurrent checkouts
  assert available (= on_hand - reserved) >= quantity for every line
     ↳ fail the whole order with a per-line message; never partially accept
  INSERT orders, order_items (with cost_price_snapshot)
  UPDATE stock_levels SET reserved = reserved + qty
  INSERT payments (pending for COD, initiated for online)
  INSERT coupon_usages, increment coupons.used_count
  compute risk score; set risk_level, verification_status
  INSERT order_events (placed)
  mark cart converted
COMMIT
then dispatch (outside the transaction):
  customer notification · admin notification · tracking events ·
  order_financials computation · customer aggregate refresh · search reindex
```
> Reservation rather than decrement: stock is held but not consumed until dispatch. In a market where a meaningful share of COD orders are cancelled before shipping, decrementing at placement makes available stock permanently wrong until someone notices.

### 4.2 Confirm order → dispatch
```
BEGIN
  assert status in (pending, confirmed, processing)
  INSERT shipments (status=pending)
  COMMIT
book with courier on the `courier` queue (outside the transaction — external HTTP must never hold a lock)
on success:
  BEGIN
    UPDATE shipments SET consignment_id, tracking_code, status=booked, charge
    UPDATE orders SET status=shipped, fulfillment_status=fulfilled, shipped_at
    FOR UPDATE stock_levels; reserved -= qty; on_hand -= qty
    INSERT stock_movements (sale_out, balance_after)
    INSERT financial_transactions (courier_charge, out, cash)
    INSERT order_events (shipped, customer_visible)
  COMMIT
on failure: shipments.status=failed, error_message; order stays confirmed; admin sees it in a retry queue
```

### 4.3 Delivered
```
BEGIN
  UPDATE orders SET status=delivered, delivered_at
  if COD: UPDATE payments SET status=paid, paid_at; orders.payment_status=paid
  INSERT financial_transactions:
      sale_revenue      (is_recognised_revenue=1, is_cash_movement=0)
      cogs              (recognised)
      discount_given    (recognised)
      courier_cod_fee   (recognised, cash)
      packaging         (recognised, cash)
  INSERT order_events (delivered, customer_visible)
COMMIT
then: recompute order_financials · update customer aggregates · update risk profile (delivered_count++) · request a review after N days
```
> Revenue is recognised here, not at placement. Cash is **not** recorded here — the courier is holding it. That becomes `cod_received` at settlement (§4.5).

> **As built — manual fulfilment (P5, until Phase 6 courier booking).** Staff move an order with `MarkOrderShipped` / `MarkOrderDelivered` (`POST /api/v1/admin/orders/{order}/ship|deliver`, bulk `/orders/bulk/ship|deliver`; orders.update + shipments.book; `Idempotency-Key` required). **Ship** follows §4.2's "on success" block minus the shipment row and the courier call, in one transaction: allowed from `confirmed`, `processing` or `ready_to_ship` (the state machine; a second ship is a 409 before any stock moves) → Inventory's `ConsumeReservedStock` (levels FOR UPDATE ascending `variant_id`, `reserved −= qty`, `sale_out` movements at the line's `cost_price_snapshot`, `reference_type = 'order'`) → `order_items.quantity_shipped = quantity` → `fulfillment_status = fulfilled` → `status = shipped`, `shipped_at`, a customer-visible `order_events` row → `OrderShipped`. There is **no `shipments` table yet**: the optional free-text courier name and tracking reference are kept on that `order_events.meta` (`manual: true`, `courier_name`, `tracking_reference`), and **no `courier_charge` entry** is written (the charge is unknown; Phase 6 booking writes it). **Deliver**: `shipped → delivered`, `delivered_at`; every still-`pending` COD payment → `paid` at its own amount through Payment's `CollectCodPayment`, and the order's `payment_status`/`paid_total`/`due_total` re-derived (`SyncOrderPaymentStatus`) — all in one transaction; then `OrderDelivered` after commit, and Finance's listener writes the recognised, non-cash rows. Nothing here writes the ledger directly, and no cash is recorded (rule 10). `fulfillment_status` stays `fulfilled` (the enum has no `delivered`). A manual courier return / failed delivery is **not** built yet (§4.4 courier return needs reversal ledger rows and `courier_return_in`). Ordering also binds Inventory's `OpenOrderReservations` (`EloquentOpenOrderReservations`: Σ `quantity − quantity_shipped` over orders whose status `holdsReservation()`, attributed to the default location), so the nightly reconciliation checks invariant 5.

### 4.4 Cancel / return
```
Cancel before shipping:
BEGIN  release reservation (reserved -= qty) · status=cancelled · reverse coupon usage ·
       INSERT order_events · risk_profile.cancelled_count++  COMMIT

Courier return (shipped but refused/undeliverable):
BEGIN  INSERT stock_movements (courier_return_in) · on_hand += qty ·
       orders.status=returned · payment_status=unpaid ·
       INSERT financial_transactions: reverse sale_revenue and cogs,
          plus courier_return_charge (out) and keep courier_charge as a sunk loss ·
       risk_profile.returned_count++ and failed_delivery_count++  COMMIT
```
> A courier return is the expensive failure mode: outbound charge paid, return charge paid, nothing sold. Both charges stay on the books. This is precisely the loss the risk module exists to reduce, and it must be visible per customer and in the P&L.

### 4.5 COD settlement
```
BEGIN
  INSERT courier_settlements + items, matched to shipments
  per matched shipment: INSERT financial_transactions
      cod_received (is_cash_movement=1, is_recognised_revenue=0)
  flag variances where cod_amount != received
  status=reconciled when all items matched
COMMIT
```

### 4.6 Receive purchase
```
BEGIN
  UPDATE purchase_items.quantity_received
  compute landed_unit_cost (apportion shipping + other cost by line value)
  FOR UPDATE stock_levels per variant
  recompute weighted average:
      avg_cost = (on_hand*avg_cost + qty*landed_unit_cost) / (on_hand + qty)
  on_hand += qty
  INSERT stock_movements (purchase_in, unit_cost=landed_unit_cost, balance_after)
  UPDATE product_variants.cost_price = avg_cost
  INSERT financial_transactions (inventory purchase, out, cash if paid)
  purchases.status = received | partially_received
COMMIT
```

> **As built (P5 purchasing, 2026-11-20).** Each delivery is a `purchase_receipts` row (GRN-YYYY-NNNN, location, `freight` · `customs_duty` · `other_cost`, `apportion_method` `value`|`quantity`|`weight`, `costs_paid_to_supplier`, `goods_value`, `payable_amount`) with `purchase_receipt_items` (`line_value`, `allocated_cost`, `landed_unit_cost`, `avg_cost_before/after`); movements reference it as `reference_type = 'purchase_receipt'`. `purchases` gains `customs_duty`, `received_value`, `ordered_at`, `cancelled_at`; its `shipping_cost`/`customs_duty`/`other_cost` are the sums actually entered on receipts. Landed costs are split per receipt by `LandedCostApportioner` exactly to the poisha (largest remainder, earliest line on a tie); by value is the default, by value with every line at zero falls back to quantity, by weight needs `weight_grams`. The receipt completing a line takes `line_total − Σ earlier receipts`, so a line's receipts always sum to its total. Stock is written by `Inventory\Actions\ReceivePurchasedStock` (ordered locks, `purchase_in` at the landed unit cost, `avg_cost` step at the receiving location, `product_variants.cost_price` = the new average); when `on_hand ≤ 0` the new average is the landed cost itself. **Deviation from the sketch above:** a receipt writes **no** ledger entry. Buying stock is not an expense — its cost reaches the P&L as `cogs` on sale through `cost_price_snapshot` — and cash leaves only when the supplier is paid: `supplier_payments` → `SupplierPaymentRecorded` → Finance writes `supplier_payment` (out, `is_cash_movement = 1`, `is_recognised_revenue = 0`). Supplier payable = `opening_balance + Σ receipts.payable_amount − Σ supplier_payments` (negative = advance). A PO is cancellable only while nothing has been received; only a draft is editable.

> **As built (owner decisions 2026-11-13).** *Close short:* a `partially_received` PO can be closed with a required reason → status `closed_short` (terminal; `purchases.closed_at`, `closed_by`, `close_reason`). No further receipt is accepted, and nothing is owed for the missing units: the payable stays received value − payments. *Third-party landed costs:* when a receipt's costs were not charged by the supplier (`costs_paid_to_supplier = false`), the receipt records `costs_vendor`, `costs_reference`, `costs_payment_method`, and `LandedCostsPaidToThirdParty` makes Finance create one expense in the seeded **capitalised** category "Freight & customs (stock)" / "পরিবহন ও শুল্ক (স্টক)" (`expense_categories.capitalised`, migration `2026_11_21_100000`). It goes through the normal expense flow (pending → approval). A capitalised category always posts ledger type `supplier_payment` with `is_recognised_revenue = 0` (approval and void) and `is_cash_movement = 1` once paid, so the cost is never a P&L line or a rollup P&L column — it is already inside the landed unit cost and reaches the P&L as COGS on sale. The expenses screen's period totals exclude capitalised categories.

### 4.7 Refund
```
BEGIN
  assert refundable amount >= requested (grand_total - refunded_total)
  INSERT refunds + refund_items
  if restock: on_hand += qty; INSERT stock_movements (customer_return_in)
  INSERT financial_transactions (refund, out, cash)
  UPDATE orders.refunded_total, payment_status
  INSERT audit_sensitive
COMMIT
then: gateway refund call on queue if online payment; recompute order_financials
```
Refunds above a configurable threshold require `approved_by` before processing.

> **As built (P5).** Split over the return's life, each step one Action in `App\Domain\Ordering\Actions\Returns` (order row locked first):
> 1. **Inspect** — `quantity_returned += n`; Inventory `RestockReturnedItems` writes `customer_return_in` for every unit and `damage_out` for damaged ones, both at `cost_price_snapshot` (rule 7), levels locked in ascending `variant_id`; order → `partially_returned` / `returned` (fulfilment → `returned` once everything is back); a `refunds` row in `requested` for the server-computed amount (each line's share of `subtotal − discount_total`, cumulative per unit so a full return refunds exactly the line; plus `shipping_charge` if claimed), capped at captured − every non-rejected refund; `audit_sensitive` (`refund_created`). Finance (`ReturnInspected`, source `order_return`): `cogs` **in** for every returned unit at the snapshot, `inventory_write_off` **out** for the damaged ones.
> 2. **Approve** — above `returns.approval_threshold` (PLACEHOLDER ৳2,000) needs `refunds.approve`; at or below, `refunds.create`. `audit_sensitive` (`refund_approved`). Finance (`RefundApproved`, source `refund`): `refund` out, recognised, **not** cash.
> 3. **Pay** — `Idempotency-Key`; a completed refund paid again is a no-op. Never more than Σ paid payments − Σ completed refunds. COD/cash orders: staff record the bKash / Nagad / cash / bank payout and reference through the COD driver; online: the gateway's `refund()` outside any transaction (claim → call → apply). Then `orders.payment_status` / `refunded_total` re-derived (`partially_refunded` / `refunded`). Finance (`RefundPaid`, source `refund_payout`): `refund` out, cash, not recognised.
> Entries are written only for an order that reached delivery (§7.6). An approved refund cannot be rejected (it is already on the ledger); a reversing entry would be needed — not offered at MVP.

---

## 5. Key Business Logic

### 5.1 Delivery charge
Resolved server-side in this order, and the resolved value is written to the order so it is never recomputed:
1. Free-shipping promotion or coupon active and order qualifies → 0
2. Zone from `shipping_district` / `shipping_upazila` → `inside_dhaka_metro` | `dhaka_suburb` | `outside_dhaka`
3. Base charge for that zone from `courier_providers` (default provider)
4. Weight surcharge above the included allowance, using summed `variant.weight_grams`
5. Store-level free-shipping threshold if configured
The quote shown in the cart and the charge on the order must come from the same code path. A mismatch between the two is a direct trust loss at the most fragile moment of the funnel.

> **As built (P4-CRT-03):** `App\Domain\Fulfilment\Services\DeliveryChargeResolver::quote()`. Store numbers (threshold, cut-off 17:00 Dhaka, ETAs, non-working days, holidays, suburb/metro district lists, not-serviced upazilas, COD advance) are `delivery.*` settings; step 1 is the `FreeShippingPromotion` seam (no promotions yet). A district-level Dhaka address (no upazila) is metro — Dhaka city is not an upazila. Full contract: docs/api/delivery.md.

### 5.2 Risk score (0–100, higher = riskier)
Weighted, transparent, and recorded in `score_breakdown` so staff see *why*:

| Signal | Weight | Notes |
|---|---|---|
| Prior cancellation rate on this phone | 25 | Needs ≥3 prior orders to carry full weight |
| Prior courier-return rate | 25 | The most expensive failure |
| Prior failed delivery attempts | 15 | |
| First-ever order from this phone | 10 | Mild — most good customers start here |
| Order value far above this customer's norm | 10 | |
| Address quality (too short, no area, gibberish) | 5 | Heuristic, flagged not punished |
| Multiple orders from one phone within minutes | 5 | Duplicate-submit vs. genuine |
| Phone fails operator-prefix validation | 5 | |

Thresholds are configurable; defaults 0–39 low, 40–69 medium, 70–100 high. High-risk orders get `verification_status=pending` and must be phone-confirmed before courier booking. **The score is advisory and must never auto-cancel an order** — a false positive loses a real customer, and the asymmetry favours a phone call.
`courier_*` columns are populated by an on-demand courier lookup and shown as a **separate, clearly labelled, timestamped** panel beside our score. The UI must not average verified external data with our heuristic.

### 5.3 Costing
MVP uses **weighted average cost** maintained on purchase receipt (§4.6), snapshotted to `order_items.cost_price_snapshot` at order creation. It is simple, stable, and adequate for a toy catalog with low cost volatility. FIFO is possible later from `purchase_items` without schema change; the decision is recorded here so no one "fixes" it inconsistently.

### 5.4 Net profit per order
```
revenue        = Σ order_items.line_total                        (excl. shipping)
cogs           = Σ (cost_price_snapshot × quantity_delivered)
gross_profit   = revenue − cogs
net_profit     = gross_profit
                 + shipping_charge_collected
                 − courier_charge − courier_cod_fee − courier_return_charge
                 − gateway_fee − packaging_cost
                 − refund_amount − return_loss
                 − allocated_marketing_cost
```
`allocated_marketing_cost` is period ad spend apportioned across delivered orders in that period; it is shown separately from direct costs because its allocation is an estimate while every other term is a recorded fact.

---

## 6. Indexing Plan

Added only where a known query needs them; every index is justified by the screen that reads it.

| Table | Index | Serves |
|---|---|---|
| `products` | `(status, published_at)`, `(primary_category_id, status)`, `(is_featured, status)`, FULLTEXT `(name)` as Meilisearch fallback | listings, home |
| `product_variants` | `(product_id, status)`, `(sku)` U, `(barcode)` U, `(price)` | variant pick, barcode scan, price sort |
| `stock_levels` | `(variant_id, location_id)` U, `(on_hand)`, `((on_hand - reserved))` virtual | availability, low-stock |
| `stock_movements` | `(variant_id, created_at)`, `(reference_type, reference_id)`, `(type, created_at)` | stock history, reconciliation |
| `orders` | `(status, created_at)`, `(payment_status)`, `(customer_id, created_at)`, `(shipping_phone)`, `(risk_level, status)`, `(number)` U, `(delivered_at)` | ops queues, phone lookup, reports |
| `order_items` | `(order_id)`, `(variant_id, created_at)`, `(product_id)` | order detail, best-sellers |
| `shipments` | `(order_id)`, `(consignment_id)`, `(provider_id, status)`, `(status, booked_at)` | courier board, webhook match |
| `courier_tracking_events` | `(shipment_id, occurred_at)`, U `(shipment_id, provider_status, occurred_at)` | timeline, idempotent ingest |
| `customers` | `(phone)` U, `(total_spent)`, `(last_order_at)`, `(orders_count)` | CRM sorting |
| `customer_risk_profiles` | `(phone)` U, `(internal_score)`, `(status)` | the phone-lookup screen |
| `financial_transactions` | `(occurred_on, type)`, `(source_type, source_id)` | P&L |
| `coupon_usages` | `(coupon_id, phone)`, `(order_id)` | per-customer limit |
| `slug_history` | `(sluggable_type, old_slug)` | 301 middleware |
| `tracking_events` | `(event_id)`, `(event_name, created_at)` | dedup, diagnostics |
| `webhook_receipts` | U `(provider, external_event_id)` | replay protection |

Composite-column order always follows equality-then-range. No index is added "just in case"; each write it slows is a real cost.

---

## 7. Consistency Guarantees

**Invariants asserted by automated tests on every build:**
1. For every `(variant_id, location_id)`: `SUM(stock_movements.quantity) = stock_levels.on_hand`.
2. For every order: `order_financials.net_profit` = the sum of its `financial_transactions`.
3. `orders.grand_total = subtotal − discount_total + shipping_charge + tax_total`.
4. `orders.due_total = grand_total − paid_total + refunded_total`.
5. `stock_levels.reserved` = the summed open-order quantity for that variant.
6. No `financial_transactions` row exists for an order that has never reached `delivered` or `returned`, except `courier_charge`.

**Nightly reconciliation job** recomputes 1, 2 and 5, writes any drift to an alert table, and notifies an admin rather than silently self-healing — drift means a code path bypassed a transaction, and silently correcting it would hide the bug.

**Concurrency rules.** Pessimistic row locks (`FOR UPDATE`) for stock, always acquired in ascending `variant_id` order. A Redis lock per order number guards state transitions against double-clicks in the admin. All external calls occur outside transactions; no lock is ever held across network I/O.

---

## 8. Seed & Reference Data

Divisions, districts (64) and upazilas with Bangla names, `is_inside_dhaka_metro` flags · Pathao and Steadfast provider rows with current rate cards · expense categories · roles and permissions · default CMS blocks and static pages · age-range and gender attribute values · a demo catalog with Bangla names, variants, images and stock for E2E tests.

---

## 9. Deferred by Design

`locations` is present with one seeded row, so multi-warehouse is a data change rather than a migration. Similarly deferred but schema-compatible: FIFO costing, multi-currency (`currency` columns already present), loyalty points, vendor/marketplace, serial-number tracking, variant-level age ranges. Each is left out of MVP deliberately; none requires a destructive migration to add.
