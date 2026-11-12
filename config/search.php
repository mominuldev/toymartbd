<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Product search (P4-CAT-14)
|--------------------------------------------------------------------------
|
| Applied to Meilisearch by `php artisan toymart:search:configure` (idempotent;
| also run by the nightly full reindex). After editing this file, run that
| command — or wait for the nightly reindex — for the change to take effect.
|
| docs/api/search.md explains every field of the index document.
|
*/

return [

    // Index name, before `scout.prefix`.
    'index' => env('SEARCH_INDEX', 'products'),

    // HTTP timeouts for the storefront's search requests. A slow Meilisearch must not hold a
    // page render: past these, the API falls back to its database search.
    'timeout_seconds' => (float) env('SEARCH_TIMEOUT_SECONDS', 2.5),
    'connect_timeout_seconds' => (float) env('SEARCH_CONNECT_TIMEOUT_SECONDS', 1.0),

    // Indexing (queue worker / artisan) timeouts are looser.
    'indexing_timeout_seconds' => (float) env('SEARCH_INDEXING_TIMEOUT_SECONDS', 30),

    // Meilisearch caps how deep page-based pagination can go (`pagination.maxTotalHits`).
    'max_total_hits' => 1000,

    /*
    | Index settings. The order of searchableAttributes IS the attribute ranking: a match in
    | the name outranks one in the brand, which outranks the category, the description, and
    | finally a SKU.
    */
    'settings' => [
        'searchableAttributes' => [
            'name', 'name_bn',
            'brand', 'brand_bn',
            'categories', 'categories_bn',
            'short_description', 'short_description_bn',
            'skus',
        ],
        'filterableAttributes' => [
            'category_slugs', 'brand_slug', 'age_min', 'age_max', 'age_bands', 'price_min', 'price_band',
            'gender', 'rating', 'rating_floor', 'is_educational', 'is_featured', 'in_stock',
        ],
        'sortableAttributes' => ['price_min', 'rating', 'rating_count', 'published_at', 'name', 'is_featured', 'popularity', 'id'],
        // Meilisearch's defaults, then two tie-breakers: in-stock first, then popular first.
        'rankingRules' => ['words', 'typo', 'proximity', 'attribute', 'sort', 'exactness', 'stock_rank:desc', 'popularity:desc'],
        'typoTolerance' => [
            'enabled' => true,
            // "lgo" (3) → no typo allowed would miss "lego"; 3 is the smallest that catches it
            // without flooding two-letter queries. Two typos from 7 letters ("helicoptar").
            'minWordSizeForTypos' => ['oneTypo' => 3, 'twoTypos' => 7],
            // Exact SKUs and brand slugs only — "lego" must never typo-match "logo".
            'disableOnAttributes' => ['skus'],
        ],
        'pagination' => ['maxTotalHits' => 1000],
        'faceting' => ['maxValuesPerFacet' => 300, 'sortFacetValuesBy' => ['*' => 'count']],
    ],

    /*
    |--------------------------------------------------------------------------
    | Synonyms — Bangla, Banglish and English toy vocabulary
    |--------------------------------------------------------------------------
    |
    | ⚠ STARTER LIST — MUST BE REVIEWED BY A NATIVE BANGLA SPEAKER before launch, then
    | extended in month one from the zero-result search log (storefront spec B6.3). The
    | Banglish spellings below are common romanisations, not an exhaustive or authoritative
    | set.
    |
    | Each group is mutually equivalent: searching any term finds products described with
    | any other term in the group ("gari" / "গাড়ি" / "car" → the same products). Terms are
    | lower-case; multi-word terms are allowed. Bangla terms are expanded automatically to
    | both Unicode spellings of ড়/ঢ়/য় (precomposed and nukta), so list each word once.
    */
    'synonyms' => [
        ['car', 'cars', 'gari', 'gaari', 'garri', 'গাড়ি', 'গাড়ী', 'গারি'],
        ['toy', 'toys', 'khelna', 'khelona', 'খেলনা'],
        ['doll', 'dolls', 'putul', 'putol', 'পুতুল'],
        ['ball', 'balls', 'bol', 'বল'],
        ['puzzle', 'puzzles', 'jigsaw', 'pazol', 'pajol', 'pazel', 'পাজল', 'পাজেল', 'ধাঁধা'],
        ['blocks', 'block', 'bricks', 'building blocks', 'blok', 'ব্লক', 'বিল্ডিং ব্লক'],
        ['bicycle', 'cycle', 'saikel', 'cycel', 'সাইকেল', 'বাইসাইকেল'],
        ['remote control', 'rc', 'remote', 'rimot', 'রিমোট', 'রিমোট কন্ট্রোল'],
        ['train', 'tren', 'rail', 'ট্রেন', 'রেলগাড়ি'],
        ['airplane', 'aeroplane', 'plane', 'biman', 'বিমান', 'উড়োজাহাজ', 'প্লেন'],
        ['helicopter', 'helikopter', 'helicoptar', 'হেলিকপ্টার'],
        ['motorbike', 'motorcycle', 'bike', 'baik', 'মোটরসাইকেল', 'বাইক'],
        ['bus', 'baas', 'বাস'],
        ['truck', 'trak', 'lorry', 'ট্রাক', 'লরি'],
        ['boat', 'ship', 'nouka', 'nowka', 'jahaj', 'নৌকা', 'জাহাজ'],
        ['kitchen set', 'cooking set', 'ranna bati', 'rannabati', 'রান্নাবাটি', 'কিচেন সেট'],
        ['teddy bear', 'teddy', 'soft toy', 'plush', 'টেডি', 'টেডি বিয়ার'],
        ['book', 'books', 'boi', 'বই'],
        ['colour', 'color', 'colours', 'colors', 'rong', 'রং', 'রঙ'],
        ['drawing', 'art', 'aka', 'akaa', 'আঁকা', 'ছবি আঁকা'],
        ['baby', 'infant', 'newborn', 'shishu', 'bachcha', 'বাচ্চা', 'শিশু'],
        ['gun', 'toy gun', 'bonduk', 'বন্দুক'],
        ['robot', 'robots', 'রোবট'],
        ['dinosaur', 'dinosaurs', 'dino', 'ডাইনোসর'],
        ['educational', 'learning', 'shikkha', 'শিক্ষা', 'শিক্ষামূলক'],
        ['game', 'games', 'khela', 'খেলা'],
        ['kite', 'ghuri', 'ঘুড়ি'],
        ['piano', 'keyboard', 'পিয়ানো'],
        ['drum', 'drums', 'ড্রাম'],
        ['guitar', 'গিটার'],
        ['lego', 'লেগো'],
        ['rubik', 'rubiks', "rubik's cube", 'rubiks cube', 'রুবিক্স কিউব'],
        ['science', 'bigyan', 'biggan', 'বিজ্ঞান'],
        ['magnet', 'chumbok', 'চুম্বক'],
        ['clay', 'play dough', 'playdough', 'play-doh', 'ক্লে'],
        ['swing', 'dolna', 'দোলনা'],
        ['slide', 'slipper', 'স্লাইড'],
        ['school bag', 'bag', 'bag pack', 'ব্যাগ', 'স্কুল ব্যাগ'],
        ['bottle', 'water bottle', 'botol', 'বোতল'],
    ],

    /*
    | "Popular searches" chips on the zero-results state and in the empty search dropdown
    | (storefront spec §A6.2). Seed values until search logs exist; `bn` is shown on /bn/.
    | ⚠ Review with the owner.
    */
    'popular' => [
        ['en' => 'LEGO', 'bn' => 'লেগো'],
        ['en' => 'Remote control cars', 'bn' => 'রিমোট কন্ট্রোল গাড়ি'],
        ['en' => 'Puzzles', 'bn' => 'পাজল'],
        ['en' => 'Dolls', 'bn' => 'পুতুল'],
    ],

    // Index-lag alert (toymart:search:lag-check, every 5 minutes). Lag = a listable product
    // saved more than `grace_seconds` ago whose document is missing or older than its row
    // (checked over the `sample_size` most recently saved), or a document count that differs
    // from the listable product count by more than `count_tolerance`.
    'lag' => [
        'grace_seconds' => (int) env('SEARCH_LAG_GRACE_SECONDS', 60),
        'sample_size' => 200,
        'count_tolerance' => 0,
    ],

];
