<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Laravel Scout — product search (P4-CAT-14)
|--------------------------------------------------------------------------
|
| Only `Product` is searchable. Index settings (searchable / filterable /
| sortable attributes, ranking, typo tolerance, synonyms) are NOT configured
| here: they live in config/search.php and are applied by the idempotent
| `php artisan toymart:search:configure` command.
|
| Scout's own queueing is OFF on purpose. Index writes are driven by domain
| events (ProductChanged, BrandChanged, CategoryChanged, StockLevelChanged)
| through App\Domain\Search\Jobs\SyncProductSearch, which runs on the `index`
| queue after the transaction commits (CLAUDE.md rule 4 — no HTTP inside a
| transaction). Scout's model observer is disabled on Product for the same
| reason; see Product::booted().
|
*/

return [

    // `meilisearch` in every real environment. `null` (the default) keeps a box without
    // Meilisearch — and the test suite — from ever opening a socket; the search API then
    // answers from its database fallback.
    'driver' => env('SCOUT_DRIVER', 'null'),

    // Prefixes every index name: lets CI, local dev and the integration tests share one
    // Meilisearch without touching each other's indexes.
    'prefix' => env('SCOUT_PREFIX', ''),

    // Off: SyncProductSearch is the queued job (see the header comment).
    'queue' => false,

    'after_commit' => false,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    'soft_delete' => false,

    'identify' => false,

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [],
        'model-settings' => [],
    ],

];
