<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Exceptions\InvalidImage;
use App\Domain\Catalog\Jobs\GenerateImageRenditions;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Add an image to a product (P4-CAT-07).
 *
 * 1. Outside any transaction: validate the real type, re-encode a sanitised master
 *    (metadata stripped, orientation applied, optional crop) and write it to the
 *    private originals disk.
 * 2. One short transaction: append the row (`processing`, last position, primary
 *    when it is the product's first image).
 * 3. After commit: GenerateImageRenditions writes the public WebP/AVIF renditions
 *    and flips the row to `ready` (rule 4 — no storage I/O inside the transaction).
 */
final class UploadProductImage
{
    public function __construct(private readonly ImagePipeline $pipeline) {}

    /**
     * @param  array{alt_text?: string|null, alt_text_bn?: string|null, variant_id?: int|null, crop?: array{x: int, y: int, width: int, height: int}|null}  $attributes
     */
    public function handle(Product $product, string $uploadedPath, array $attributes = []): ProductImage
    {
        $variantId = $attributes['variant_id'] ?? null;

        if ($variantId !== null && ! ProductVariant::query()->whereKey($variantId)->where('product_id', $product->id)->exists()) {
            throw ValidationException::withMessages(['variant_id' => 'The variant does not belong to this product.']);
        }

        $max = config('media.max_per_product');

        if (is_int($max) && ProductImage::query()->where('product_id', $product->id)->count() >= $max) {
            throw ValidationException::withMessages(['file' => "A product can have up to {$max} images."]);
        }

        try {
            $master = $this->pipeline->master($uploadedPath, $attributes['crop'] ?? null);
        } catch (InvalidImage $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $base = "products/{$product->id}/".Str::lower((string) Str::ulid());
        Storage::disk(MediaUrls::originalsDisk())->put("{$base}/master.webp", $master['bytes']);

        try {
            $image = DB::transaction(function () use ($product, $attributes, $variantId, $base, $master): ProductImage {
                Product::query()->whereKey($product->id)->lockForUpdate()->first();

                $existing = ProductImage::query()->where('product_id', $product->id);
                $position = ((int) (clone $existing)->max('position')) + ((clone $existing)->exists() ? 1 : 0);
                $hasPrimary = (clone $existing)->where('is_primary', true)->exists();

                return ProductImage::query()->create([
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'path' => $base,
                    'width' => $master['width'],
                    'height' => $master['height'],
                    'processing_status' => ImageProcessingStatus::Processing,
                    'rendition_widths' => null,
                    'alt_text' => self::clean($attributes['alt_text'] ?? null),
                    'alt_text_bn' => self::clean($attributes['alt_text_bn'] ?? null),
                    'position' => $position,
                    'is_primary' => ! $hasPrimary,
                ]);
            });
        } catch (Throwable $e) {
            Storage::disk(MediaUrls::originalsDisk())->deleteDirectory($base);

            throw $e;
        }

        GenerateImageRenditions::dispatch($image->id);

        return $image->refresh();
    }

    private static function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
