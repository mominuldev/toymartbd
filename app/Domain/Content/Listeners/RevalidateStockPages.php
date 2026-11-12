<?php

declare(strict_types=1);

namespace App\Domain\Content\Listeners;

use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Inventory\Events\StockLevelChanged;

/**
 * StockLevelChanged → purge the product's page (stock state, low-stock count) and the
 * product lists (`in_stock` on cards, the availability facet). Tag vocabulary:
 * docs/api/revalidation.md.
 *
 * Every reservation fires this, so a busy day purges `products` often. If that shows up
 * in cache hit rates, purge `products` only when the product's in-stock flag flips.
 */
final class RevalidateStockPages
{
    public function handle(StockLevelChanged $event): void
    {
        $slug = Product::withTrashed()->whereKey($event->productId)->value('slug');
        if (! is_string($slug)) {
            return;
        }

        RevalidateStorefrontTags::dispatch(['products', 'product:'.$slug]);
    }
}
