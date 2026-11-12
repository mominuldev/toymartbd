<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductImageMediaMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edit an image's alt text, variant or primary flag. Only the keys present are
 * written. `is_primary: true` moves the primary flag here from whichever image had
 * it; `false` on the primary is refused (choose another image instead), so a
 * product with images always has exactly one primary.
 */
final readonly class UpdateProductImage
{
    public function __construct(private ProductImageMediaMirror $mirror) {}

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

            // Alt text has one source of truth: a library-linked image writes it to its
            // media row, so the library and every product using the file agree.
            $media = $image->media_id !== null ? Media::query()->lockForUpdate()->find($image->media_id) : null;

            foreach (['alt_text', 'alt_text_bn'] as $key) {
                if (array_key_exists($key, $changes)) {
                    $value = $changes[$key] !== null ? trim($changes[$key]) : null;
                    $value = $value === '' ? null : $value;

                    if ($media !== null) {
                        $media->{$key} = $value;
                    } else {
                        $image->{$key} = $value;
                    }
                }
            }

            if ($media !== null && $media->isDirty()) {
                $media->save();
                $this->mirror->sync($media);
                $image->setRelation('media', $media);
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
