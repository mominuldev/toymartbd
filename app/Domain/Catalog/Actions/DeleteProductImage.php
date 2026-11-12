<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Jobs\DeleteMediaFiles;
use App\Domain\Catalog\Media\Enums\MediaOrigin;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * Delete a product image. Positions close up; if it was the primary, the first
 * remaining image becomes primary. Its files are removed by a queued job after
 * commit (rule 4), and only when no other image row shares them.
 */
final readonly class DeleteProductImage
{
    public function __construct(private MediaUsages $usages) {}

    public function handle(ProductImage $image): void
    {
        DB::transaction(function () use ($image): void {
            $product = Product::query()->lockForUpdate()->findOrFail($image->product_id);
            $image = ProductImage::query()->lockForUpdate()->find($image->id);

            if ($image === null) {
                return;
            }

            $path = $image->path;
            $mediaId = $image->media_id;
            $wasPrimary = $image->is_primary;
            $image->delete();

            // A product-origin file (uploaded through the product form) goes with its last
            // usage, as before the library existed; a library file stays, as Unused
            // (docs/api/media-library.md, decision 10).
            if ($mediaId !== null) {
                $path = $this->releaseMedia($mediaId);
            }

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

    /** @return string|null the base to delete after commit, or null to keep the files */
    private function releaseMedia(int $mediaId): ?string
    {
        $media = Media::query()->lockForUpdate()->find($mediaId);

        if ($media === null || $media->origin !== MediaOrigin::Product || $this->usages->isUsed($media->id)) {
            return null;
        }

        $base = $media->base_path;
        $media->delete();

        return $base;
    }
}
