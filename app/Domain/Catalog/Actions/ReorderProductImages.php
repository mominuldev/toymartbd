<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Set the display order of a product's images: `$orderedIds` must be exactly the
 * product's image ids, each once (positions become 0…n-1). The primary flag is
 * independent of position.
 */
final class ReorderProductImages
{
    /** @param list<int> $orderedIds */
    public function handle(Product $product, array $orderedIds): Product
    {
        return DB::transaction(function () use ($product, $orderedIds): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);

            /** @var list<int> $existing */
            $existing = ProductImage::query()->where('product_id', $product->id)->lockForUpdate()->pluck('id')->all();

            $given = $orderedIds;
            sort($given);
            sort($existing);

            if ($given !== $existing) {
                throw ValidationException::withMessages(['ids' => 'Send every image id of this product exactly once.']);
            }

            $changed = false;

            foreach ($orderedIds as $position => $id) {
                $changed = ProductImage::query()->whereKey($id)->where('position', '!=', $position)->update(['position' => $position]) > 0 || $changed;
            }

            if ($changed) {
                ProductChanged::dispatch($product->id, $product->slug);
            }

            return $product;
        });
    }
}
