<?php

declare(strict_types=1);

namespace App\Domain\Search\Listeners;

use App\Domain\Catalog\Events\BrandChanged;
use App\Domain\Catalog\Events\CategoryChanged;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Category;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Search\Jobs\SyncProductSearch;
use Illuminate\Support\Facades\DB;

/**
 * Catalog and stock events → SyncProductSearch for every product whose search document
 * they change (P4-CAT-14). Each event is already held until its transaction commits.
 *
 * - ProductChanged: fields, categories, variants, relations, images, archive.
 * - BrandChanged: the brand's name/status is denormalised into its products' documents.
 * - CategoryChanged: names, status and tree position feed `categories` and
 *   `category_slugs` of every product in the category's subtree.
 * - StockLevelChanged: `in_stock` / `stock_rank`.
 *
 * Product ids are resolved here (a cheap indexed query) and the heavy work runs on the
 * `index` queue.
 */
final class ReindexAffectedProducts
{
    public function onProduct(ProductChanged $event): void
    {
        SyncProductSearch::dispatchFor([$event->productId]);
    }

    public function onStock(StockLevelChanged $event): void
    {
        SyncProductSearch::dispatchFor([$event->productId]);
    }

    public function onBrand(BrandChanged $event): void
    {
        SyncProductSearch::dispatchFor(
            DB::table('products')->where('brand_id', $event->brandId)->orderBy('id')->pluck('id')->all(),
        );
    }

    public function onCategory(CategoryChanged $event): void
    {
        $category = Category::withTrashed()->find($event->categoryId, ['id', '_lft', '_rgt']);
        if ($category === null) {
            return;
        }

        // The whole subtree: a move or rename changes every descendant's ancestor trail.
        $categoryIds = DB::table('categories')
            ->where('_lft', '>=', $category->_lft)
            ->where('_rgt', '<=', $category->_rgt)
            ->pluck('id');

        SyncProductSearch::dispatchFor(
            DB::table('category_product')->whereIn('category_id', $categoryIds)->distinct()->orderBy('product_id')->pluck('product_id')->all(),
        );
    }
}
