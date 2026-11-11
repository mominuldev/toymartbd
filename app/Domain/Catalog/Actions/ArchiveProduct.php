<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Archive a product: it leaves the storefront, but the row, its variants and their
 * SKUs stay — orders and stock movements reference them. Variants keep their own
 * status, so restoring the product (status back to active) restores the matrix as it
 * was. Idempotent — archiving an archived product changes nothing and fires nothing.
 */
final class ArchiveProduct
{
    public function handle(Product $product): Product
    {
        return DB::transaction(function () use ($product): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($product->status === ProductStatus::Archived) {
                return $product;
            }

            $product->status = ProductStatus::Archived;
            $product->save();

            ProductChanged::dispatch($product->id, $product->slug);

            return $product;
        });
    }
}
