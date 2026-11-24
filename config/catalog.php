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

];
