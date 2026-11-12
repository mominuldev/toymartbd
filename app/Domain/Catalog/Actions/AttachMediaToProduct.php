<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Media\Actions\UploadMedia;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductImageMediaMirror;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add an existing library image to a product's gallery (`POST /products/{product}/images`
 * with `media_id`, docs/api/media-library.md). The image row points at the media and
 * shares its files; nothing is copied or re-encoded. Same rules as an upload: at most
 * `media.max_per_product` images, appended last, primary when it is the first.
 *
 * Alt text sent with the request is written to the media (one source of truth), so it
 * shows on every product that uses the file.
 */
final readonly class AttachMediaToProduct
{
    public function __construct(private ProductImageMediaMirror $mirror) {}

    /**
     * @param  array{alt_text?: string|null, alt_text_bn?: string|null, variant_id?: int|null}  $attributes
     */
    public function handle(Product $product, int $mediaId, array $attributes = []): ProductImage
    {
        $variantId = $attributes['variant_id'] ?? null;

        if ($variantId !== null && ! ProductVariant::query()->whereKey($variantId)->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['variant_id' => 'The variant does not belong to this product.']);
        }

        return DB::transaction(function () use ($product, $mediaId, $attributes, $variantId): ProductImage {
            // Lock order: product, then media — the same order as every product-image write.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();
            $media = Media::query()->lockForUpdate()->find($mediaId);

            if ($media === null) {
                throw ValidationException::withMessages(['media_id' => (string) __('validation.exists', ['attribute' => 'media id'])]);
            }

            if (! $media->isImage()) {
                throw ValidationException::withMessages(['media_id' => (string) __('media.attach.not_image')]);
            }

            $existing = ProductImage::query()->where('product_id', $product->id);

            if ((clone $existing)->where('media_id', $media->id)->exists()) {
                throw ValidationException::withMessages(['media_id' => (string) __('media.attach.already_attached')]);
            }

            $max = config('media.max_per_product');

            if (is_int($max) && (clone $existing)->count() >= $max) {
                throw ValidationException::withMessages(['media_id' => "A product can have up to {$max} images."]);
            }

            $altChanged = false;

            foreach (['alt_text', 'alt_text_bn'] as $key) {
                if (array_key_exists($key, $attributes) && $attributes[$key] !== null) {
                    $media->{$key} = UploadMedia::clean($attributes[$key]);
                    $altChanged = $altChanged || $media->isDirty($key);
                }
            }

            if ($altChanged) {
                $media->save();
            }

            $position = ((int) (clone $existing)->max('position')) + ((clone $existing)->exists() ? 1 : 0);
            $hasPrimary = (clone $existing)->where('is_primary', true)->exists();

            $image = ProductImage::query()->create([
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'media_id' => $media->id,
                'position' => $position,
                'is_primary' => ! $hasPrimary,
            ]);

            // Fill the mirror columns of the new row (and of every other row, when alt changed).
            $this->mirror->sync($media, revalidate: $altChanged);

            if (! $altChanged) {
                ProductChanged::dispatch($product->id, $product->slug);
            }

            return $image->refresh();
        });
    }
}
