<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalog
|--------------------------------------------------------------------------
*/

return [

    // In-store barcodes (GenerateVariantBarcodes): EAN-13 = prefix + zero-padded running number +
    // check digit. GS1 reserves 20–29 for "restricted circulation" — numbers a shop assigns to its
    // own goods, never seen outside the shop — so the default `20` cannot collide with a
    // manufacturer's GTIN. Digits only, 2–3 long; the running number fills the rest of the 12.
    'barcode' => [
        'prefix' => (string) env('CATALOG_BARCODE_PREFIX', '20'),
    ],

    // A product card shows the "New" badge for this many days after the product is published
    // (StorefrontProductCardResource `is_new`; Smart Junior Product Card "নতুন").
    'new_badge_days' => (int) env('CATALOG_NEW_BADGE_DAYS', 30),

    // Frequently bought together (PRD X-03): a pair needs at least this many co-purchase orders in
    // `product_copurchases` (last 180 days, non-cancelled) before it is shown — or used to fill the
    // cart and confirmation rails. Below it the signal is noise.
    'fbt_min_orders' => (int) env('CATALOG_FBT_MIN_ORDERS', 3),

    // Recommendation caps (PRD §3.1a, CLAUDE.md rules 25b/25c), enforced on read by
    // App\Domain\Catalog\Recommendations\RecommendationResolver — docs/api/recommendations.md.
    'recommendations' => [
        'upsell' => 2,
        'cross_sell' => 4,
        'age_progression' => 4,
        // Co-purchased items beside the product itself: the block shows 2–3 items in all.
        'fbt_items' => 2,
        'cart_cross_sell' => 8,
        'confirmation_cross_sell' => 4,
    ],

];
