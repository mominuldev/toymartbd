<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Jobs\DeleteMediaFiles;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Delete a product image. Positions close up; if it was the primary, the first
 * remaining image becomes primary. Its files are removed by a queued job after
 * commit (rule 4), and only when no other image row shares them.
 */
final class DeleteProductImage
{
    public function handle(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $product = Product::query()->lockForUpdate()->findOrFail($image->product_id);
            $image = ProductImage::query()->lockForUpdate()->find($image->id);

            if ($image === null) {
                return;
            }

            $path = $image->path;
            $wasPrimary = $image->is_primary;
            $image->delete();

            $remaining = ProductImage::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')->get();

            foreach ($remaining as $position => $other) {
                $other->position = $position;

                if ($wasPrimary && $position === 0) {
                    $other->is_primary = true;
                }

                $other->save();
            }

            if ($path !== null && $path !== '') {
                DeleteMediaFiles::dispatch($path);
            }

            ProductChanged::dispatch($product->id, $product->slug);
        });
    }
}
