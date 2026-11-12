<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Contracts\ProductSearchDocuments;
use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Search\Documents\ProductDocumentBuilder;
use App\Domain\Search\Listeners\ReindexAffectedProducts;
use App\Domain\Search\Support\MeilisearchClients;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client;

/**
 * Product search wiring (P4-CAT-14): the document builder behind Product's Scout
 * methods, Meilisearch clients with timeouts, and the event → reindex listeners.
 */
final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MeilisearchClients::class);

        // Scoped, not bound: Product::makeSearchableUsing() primes it for a batch and
        // toSearchableArray() must read that same instance. Reset between queued jobs.
        $this->app->scoped(ProductSearchDocuments::class, ProductDocumentBuilder::class);

        // Scout's own client has no HTTP timeout; give it the indexing one.
        $this->app->singleton(Client::class, fn ($app): Client => $app->make(MeilisearchClients::class)->forIndexing());
    }

    public function boot(): void
    {
        Event::listen(ProductChanged::class, [ReindexAffectedProducts::class, 'onProduct']);
        Event::listen(BrandChanged::class, [ReindexAffectedProducts::class, 'onBrand']);
        Event::listen(CategoryChanged::class, [ReindexAffectedProducts::class, 'onCategory']);
        Event::listen(StockLevelChanged::class, [ReindexAffectedProducts::class, 'onStock']);
    }
}
