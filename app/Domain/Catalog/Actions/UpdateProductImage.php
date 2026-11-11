<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an image's alt text, variant or primary flag. Only the keys present are
 * written. `is_primary: true` moves the primary flag here from whichever image had
 * it; `false` on the primary is refused (choose another image instead), so a
 * product with images always has exactly one primary.
 */
final class UpdateProductImage
{
    /**
     * @param  array{alt_text?: string|null, alt_text_bn?: string|null, variant_id?: int|null, is_primary?: bool}  $changes
     */
    public function handle(ProductImage $image, array $changes): ProductImage
    {
        return DB::transaction(function () use ($image, $changes): ProductImage {
            $product = Product::query()->lockForUpdate()->findOrFail($image->product_id);
            $image = ProductImage::query()->lockForUpdate()->findOrFail($image->id);

            if (array_key_exists('variant_id', $changes) && $changes['variant_id'] !== null
                && ! ProductVariant::query()->whereKey($changes['variant_id'])->where('product_id', $product->id)->exists()) {
                throw ValidationException::withMessages(['variant_id' => 'The variant does not belong to this product.']);
            }

            foreach (['alt_text', 'alt_text_bn'] as $key) {
                if (array_key_exists($key, $changes)) {
                    $value = $changes[$key] !== null ? trim($changes[$key]) : null;
                    $image->{$key} = $value === '' ? null : $value;
                }
            }

            if (array_key_exists('variant_id', $changes)) {
                $image->variant_id = $changes['variant_id'];
            }

            if (array_key_exists('is_primary', $changes)) {
                if ($changes['is_primary'] === false && $image->is_primary) {
                    throw ValidationException::withMessages(['is_primary' => 'Make another image primary instead.']);
                }

                if ($changes['is_primary'] === true && ! $image->is_primary) {
                    ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->update(['is_primary' => false]);
                    $image->is_primary = true;
                }
            }

            if ($image->isDirty()) {
                $image->save();
                ProductChanged::dispatch($product->id, $product->slug);
            }

            return $image;
        });
    }
}
