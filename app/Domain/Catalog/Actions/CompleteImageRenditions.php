<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Record that an image's renditions exist (`ready` + the widths written), or that
 * generation failed. Called by GenerateImageRenditions after the files are on disk.
 * A `ready` image changes what the storefront renders, so ProductChanged fires.
 */
final class CompleteImageRenditions
{
    /** @param list<int> $widths */
    public function handle(ProductImage $image, array $widths): void
    {
        DB::transaction(function () use ($image, $widths): void {
            $updated = ProductImage::query()->whereKey($image->id)->update([
                'processing_status' => ImageProcessingStatus::Ready->value,
                'rendition_widths' => json_encode($widths, JSON_THROW_ON_ERROR),
            ]);

            if ($updated === 0) {
                return;
            }

            $slug = Product::query()->whereKey($image->product_id)->value('slug');

            if (is_string($slug)) {
                ProductChanged::dispatch($image->product_id, $slug);
            }
        });
    }

    public function fail(int $imageId): void
    {
        DB::transaction(function () use ($imageId): void {
            ProductImage::query()->whereKey($imageId)
                ->where('processing_status', ImageProcessingStatus::Processing->value)
                ->update(['processing_status' => ImageProcessingStatus::Failed->value]);
        });
    }
}
