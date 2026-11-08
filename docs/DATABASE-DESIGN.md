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
`id` · `name` · `name_bn` NULL · `slug` UNIQUE · `type` (`simple`|`variable`) · `brand_id` FK NULL · `primary_category_id` FK · `short_description` · `description` LONGTEXT · `specifications` JSON NULL · `age_min_months` SMALLINT NULL · `age_max_months` SMALLINT NULL · `gender` (`boys`|`girls`|`unisex`) NULL · `is_educational` BOOL · `safety_note` NULL · `warranty_months` NULL · `status` (`draft`|`active`|`archived`) · `is_featured` BOOL · `rating_avg` DECIMAL(3,2) NULL · `rating_count` INT · `view_count` INT · `published_at` NULL · timestamps · `deleted_at`

> `age_min_months`/`age_max_months` in **months**, not years, because the 0–36-month range is where parents shop most precisely and "1–3 years" loses the distinction between a 6-month-old and a 30-month-old. No verified competitor offers age filtering; it is the single most valuable catalog attribute for this category.

**`product_variants`**
`id` · `product_id` FK CASCADE · `sku` UNIQUE · `barcode` UNIQUE NULL · `name` NULL (e.g. "Red / Large") · `cost_price` DECIMAL(14,2) · `price` DECIMAL(14,2) · `compare_at_price` NULL · `wholesale_price` NULL · `weight_grams` INT · `is_default` BOOL · `position` · `status` · timestamps
Index: `(product_id, status)`, `(price)`

> `cost_price` here is the **current** replacement cost used for margin display. Historical cost for profit always comes from `order_items.cost_price_snapshot` or, where exact costing is required, from `purchase_items` via FIFO. MVP uses weighted-average cost maintained on receipt; see §5.3.

**`product_attributes`** `id` · `name` · `name_bn` · `slug` UNIQUE · `type` (`select`|`color`|`text`) · `is_variant_defining` BOOL · `is_filterable` BOOL · `position`
**`product_attribute_values`** `id` · `attribute_id` FK · `value` · `value_bn` · `slug` · `color_hex` NULL · `position`
**`variant_attribute_values`** `variant_id` FK · `attribute_id` FK · `attribute_value_id` FK · PK `(variant_id, attribute_id)`

**`categories`** `id` · `parent_id` FK NULL · `name` · `name_bn` · `slug` UNIQUE · `description` · `image_path` · `icon` · `banner_path` · `position` · `is_featured` BOOL · `show_in_menu` BOOL · `status` · `_lft`/`_rgt`/`depth` (nested set, via `kalnoy/nestedset`) · timestamps · `deleted_at`

> Nested set rather than a recursive CTE: the mega menu and breadcrumbs read the tree on nearly every page and writes are rare. One indexed range query beats a recursive query per request.

**`category_product`** `category_id` · `product_id` · PK both — products appear in multiple categories; `products.primary_category_id` drives the canonical URL and breadcrumb to avoid duplicate-content issues.

**`brands`** `id` · `name` · `slug` UNIQUE · `logo_path` · `description` · `country` · `status` · `position`
**`product_images`** `id` · `product_id` · `variant_id` NULL · `path` · `alt_text` · `alt_text_bn` · `position` · `is_primary`
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

**`children_profiles`** `id` · `customer_id` · `name` NULL · `birth_date` · `gender` · `interests` JSON
> Optional, opt-in. Enables age-appropriate recommendations and birthday reminders — the highest-value retention feature available in this category and absent from every competitor checked. Must be clearly consented to; it is children's data.

**`addresses`** `id` · `customer_id` FK NULL · `label` · `recipient_name` · `phone` · `alt_phone` · `division_id` · `district_id` · `upazila_id` NULL · `area` · `address_line` · `landmark` · `postcode` NULL · `type` (`home`|`office`|`other`) · `is_default_shipping` · `is_default_billing` · `lat`/`lng` NULL · timestamps

**`divisions`** / **`districts`** / **`upazilas`** / **`unions`** / **`post_offices`** — static reference data: `name`, `name_bn`, parent FK, official P-code, and `is_inside_dhaka_metro` / `is_dhaka_subcity` BOOL on upazilas to drive the three-tier delivery-charge rule.

> **Source: `devfaysal/laravel-bangladesh-geocode`** (MIT, actively maintained) — 8 divisions / 64 districts / **495 upazilas** / 4,548 unions / 1,357 post offices, with English and Bangla names, real migrations and Eloquent models. Do **not** base this on `nuhil/bangladesh-geocode` despite its popularity: dormant since 2023, outdated spellings, 494 upazilas, and no postcodes. Reconcile once against the OCHA/BBS COD-AB boundary file, which resolves the 494/495/507 confusion — **495 upazilas plus 12 city corporations** — and store the official P-codes so a future reconciliation has a stable key. `data.gov.bd` has nothing usable (verified: it runs Drupal, not CKAN). **No official bulk postcode list exists**, so `postcode` is optional everywhere with lookup assistance, never a required field.

> **Phone validation** is `/^(?:\+?880|0)1[3-9]\d{8}$/`, stored E.164. Valid prefixes are **013–019 only**; 010/011/012 are rejected (011 was Citycell, defunct) along with the newer 06x IoT series. libphonenumber alone is insufficient — it is more permissive and accepts 011 — so the regex is layered on top of `propaganistas/laravel-phone` with `->country('BD')->type('mobile')`, and the rules are passed as an **array** because the pattern contains `|`. **The operator is never inferred from the prefix:** number portability has existed since 2018, and 016 is Robi/Cirkle, not Airtel — that brand was retired on 17 August 2026.

**`customer_risk_profiles`** `id` · `phone` UNIQUE (the key, not `customer_id`) · `customer_id` FK NULL · `total_orders` · `delivered_count` · `cancelled_count` · `returned_count` · `failed_delivery_count` · `refused_count` · `internal_score` TINYINT (0–100) · `score_breakdown` JSON · `score_computed_at` · `courier_data` JSON NULL · `courier_checked_at` NULL · `courier_delivered_count` NULL · `courier_cancelled_count` NULL · `status` (`normal`|`watch`|`blacklist`|`whitelist`) · `status_reason` · `status_set_by` · `status_set_at` · `manual_note` · timestamps

> Three deliberate separations. (a) Keyed on **phone**, so a repeat fake-order caller is recognised even as a new guest. (b) `internal_score` and `score_breakdown` are **our** computation and labelled as such in the UI. (c) `courier_*` columns hold **verified external data** with the timestamp of retrieval. The admin UI must never blend these into one number — staff decide whether to call a customer, and they need to know whether the evidence is ours or the courier's, and how old it is.

**`customer_notes`** `id` · `customer_id` · `user_id` · `body` · `is_pinned` · `created_at`

### 3.4 Cart & Orders

**`carts`** `id` · `token` UUID UNIQUE · `customer_id` NULL · `coupon_id` NULL · `currency` · `subtotal` · `discount_total` · `shipping_total` · `grand_total` · `item_count` · `shipping_address_id` NULL · `notes` · `abandoned_at` NULL · `recovery_email_sent_at` NULL · `recovered_order_id` NULL · `expires_at` · timestamps
**`cart_items`** `id` · `cart_id` · `variant_id` · `quantity` · `unit_price` (snapshot at add) · `line_total` · `added_at`

> Server-side cart keyed by an opaque token in an httpOnly cookie. Totals are always the API's answer. `abandoned_at` plus `recovered_order_id` makes abandoned-cart recovery measurable rather than anecdotal.

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

### 3.5 Payments, Refunds, Invoices

**`payments`** `id` · `order_id` · `method` (`cod`|`sslcommerz`|`bkash`|`nagad`|`card`|`bank_transfer`|`cash`) · `gateway` NULL · `amount` · `currency` · `status` (`pending`|`initiated`|`authorised`|`paid`|`failed`|`cancelled`|`expired`) · `transaction_id` NULL · `gateway_reference` NULL · `gateway_payload` JSON · `fee_amount` · `net_amount` · `paid_at` · `failure_reason` · `collected_by` NULL · `verified_at` NULL · timestamps
Index: `(order_id)`, `(transaction_id)` UNIQUE where not null, `(status, created_at)`

> `fee_amount` recorded per payment, not estimated at report time, so gateway charges are real numbers in the P&L.

**`refunds`** `id` · `order_id` · `payment_id` NULL · `amount` · `reason` · `type` (`full`|`partial`) · `method` · `status` · `gateway_reference` · `restock` BOOL · `requested_by` · `approved_by` NULL · `approved_at` · `processed_at` · `note` · timestamps
**`refund_items`** `refund_id` · `order_item_id` · `quantity` · `amount`

**`invoices`** `id` · `type` (`sales`|`purchase`) · `number` UNIQUE · `order_id` NULL · `purchase_id` NULL · `issued_to` JSON (snapshot) · `issued_by` JSON (company snapshot) · `subtotal` · `discount` · `shipping` · `tax` · `total` · `amount_in_words` · `issued_at` · `due_at` NULL · `pdf_path` NULL · `template` · `created_by`
> Company details are snapshotted onto the invoice. Reprinting a six-month-old invoice must show the address and BIN that were current then, not today's.

### 3.6 Fulfilment & Courier

**`courier_providers`** `id` · `code` (`pathao`|`steadfast`|...) · `name` · `is_active` · `is_default` · `credentials` JSON **encrypted** · `settings` JSON · `supports_bulk` · `supports_webhook` · `webhook_secret` encrypted · `base_charge_dhaka` · `base_charge_suburb` · `base_charge_outside` · `per_kg_charge` · `cod_fee_percent` · `return_charge` · `balance` · `balance_checked_at` · `position`
> Charges are stored and editable because courier rate cards change without notice, and they feed delivery-charge quoting and P&L. Credentials are encrypted at rest and only decrypted at call time.

**`courier_locations`** `id` · `provider_id` · `external_city_id` · `external_city_name` · `external_zone_id` · `external_zone_name` · `external_area_id` · `external_area_name` · `district_id` FK NULL · `upazila_id` FK NULL · `is_mapped` BOOL · `synced_at`
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
> **Partial delivery is a real state.** Both providers support delivering some items of a multi-item parcel. `partially_delivered` settles COD for the delivered lines, restocks the rest via `courier_return_in`, and sets the order to `partially_returned`. AppByte has no such state; orders like these are exactly where money quietly goes missing.

**`courier_tracking_events`** `id` · `shipment_id` · `provider_id` · `normalised_status` · `provider_status` · `message` · `location` · `occurred_at` · `received_at` · `payload` JSON · `source` (`webhook`|`poll`|`manual`)
Index: `(shipment_id, occurred_at)`, UNIQUE `(shipment_id, provider_status, occurred_at)` to make ingestion idempotent.

**`courier_settlements`** `id` · `provider_id` · `reference` · `period_start` · `period_end` · `cod_collected` · `delivery_charges` · `cod_fees` · `return_charges` · `adjustments` · `net_received` · `received_at` · `status` (`expected`|`received`|`disputed`|`reconciled`) · `statement_path` · `note`
**`courier_settlement_items`** `settlement_id` · `shipment_id` · `order_id` · `cod_amount` · `charges` · `net` · `is_matched` BOOL · `variance`
> This is how COD receivables are closed out. Delivered COD orders create a receivable; a settlement matches against them and surfaces variances. Without this table, "the courier owes us money" is an untracked guess — and it is usually the largest number on the balance sheet for a BD COD business.

### 3.7 Finance

**`expense_categories`** `id` · `name` · `type` (`cogs`|`operating`|`marketing`|`logistics`|`other`) · `is_fixed` BOOL · `parent_id` NULL
**`expenses`** `id` · `category_id` · `amount` · `expense_date` · `payment_method` · `reference` · `vendor` · `description` · `attachment_path` · `is_recurring` · `recurrence` · `order_id` NULL · `shipment_id` NULL · `created_by` · `approved_by` NULL
**`financial_transactions`** (append-only ledger) `id` · `type` · `direction` (`in`|`out`) · `amount` · `occurred_on` DATE (Dhaka date) · `source_type`/`source_id` polymorphic · `category_id` NULL · `description` · `is_cash_movement` BOOL · `is_recognised_revenue` BOOL · `created_at`
Index: `(occurred_on, type)`, `(source_type, source_id)`, `(type, occurred_on)`

`type` values: `sale_revenue` · `cogs` · `discount_given` · `shipping_revenue` · `courier_charge` · `courier_cod_fee` · `courier_return_charge` · `gateway_fee` · `packaging` · `marketing` · `salary` · `rent` · `utility` · `refund` · `inventory_write_off` · `supplier_payment` · `cod_received` · `other_income` · `other_expense`

> **Two booleans carry the whole revenue-versus-cash distinction.** `is_recognised_revenue` marks entries that belong in the P&L. `is_cash_movement` marks entries that moved actual money. A delivered COD order writes `sale_revenue` (recognised, not cash); the later courier remittance writes `cod_received` (cash, not revenue). Receivables = recognised minus received. This is why the P&L and the cash position can both be correct and different.

**`daily_financial_summaries`** `summary_date` PK · all the above aggregated, plus `orders_placed`, `orders_delivered`, `orders_cancelled`, `orders_returned`, `new_customers`, `units_sold`, `cod_receivable_closing`
> Nightly rollup at 01:00 Dhaka. Dashboards read this, not the ledger. A 12-month revenue chart must not scan a million ledger rows.

### 3.8 Marketing

**`coupons`** `id` · `code` UNIQUE · `type` (`fixed`|`percent`|`free_shipping`) · `value` · `max_discount` NULL · `min_order_amount` · `usage_limit_total` · `usage_limit_per_customer` · `used_count` · `starts_at` · `ends_at` · `applies_to` (`all`|`categories`|`products`|`brands`) · `conditions` JSON · `excludes` JSON · `first_order_only` BOOL · `customer_segment_id` NULL · `is_active` · `created_by`
**`coupon_usages`** `coupon_id` · `order_id` · `customer_id` · `phone` · `discount_amount` · `used_at` — `phone` is stored so per-customer limits survive guest checkout.
**`promotions`** `id` · `name` · `type` (`flash_sale`|`bundle`|`buy_x_get_y`|`category_discount`|`tiered`) · `priority` · `is_stackable` BOOL · `rules` JSON · `starts_at` · `ends_at` · `is_active` · `banner_path` · `usage_count`
> `priority` and `is_stackable` exist because overlapping promotions are the main source of "the price on the page was wrong" incidents. The pricing pipeline applies promotions in priority order and stops at the first non-stackable match; the resolved breakdown is written to the order so any later dispute is answerable.

**`customer_segments`** `id` · `name` · `type` (`dynamic`|`static`) · `rules` JSON · `customer_count` · `last_computed_at`
**`campaigns`** `id` · `name` · `channel` (`sms`|`whatsapp`|`email`) · `segment_id` · `template_id` · `scheduled_at` · `status` · `sent_count` · `delivered_count` · `failed_count` · `cost`
**`notification_logs`** `id` · `channel` · `recipient` · `template` · `payload` JSON · `status` · `provider_message_id` · `cost` · `error` · `notifiable_type`/`notifiable_id` · `sent_at`
> Per-message cost logged so SMS/WhatsApp spend lands in the P&L as a real figure.

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

**`redirects`** `id` · `from_path` UNIQUE · `to_path` · `status_code` (301|302) · `hit_count` · `last_hit_at` · `is_active` · `note`

### 3.10 System

**`tracking_events`** `id` · `event_id` UUID (shared with the browser event for deduplication) · `event_name` · `source` (`browser`|`server`) · `customer_id` NULL · `session_id` · `order_id` NULL · `value` · `currency` · `payload` JSON · `consent_state` JSON · `ga4_status` · `ga4_response` · `meta_status` · `meta_response` · `dispatched_at` · `created_at`
**`webhook_receipts`** `id` · `provider` · `external_event_id` · `signature_valid` BOOL · `payload` JSON · `headers` JSON · `processed_at` NULL · `result` · `error` · UNIQUE `(provider, external_event_id)`
**`settings`** `key` PK · `value` JSON · `group` · `is_encrypted` BOOL · `updated_by` · `updated_at`
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
