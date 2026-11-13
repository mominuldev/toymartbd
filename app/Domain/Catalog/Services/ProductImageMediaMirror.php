<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Events\ProductChanged;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;

/**
 * Keeps the legacy file and alt columns of every product image linked to a media row
 * equal to the media (expand phase of expand-and-contract, docs/api/media-library.md).
 * Reads never use them for a linked row — ProductImage delegates to its media — so this
 * mirror exists only to keep a code rollback safe. Called inside the caller's
 * transaction; fires ProductChanged (after commit) for every product that shows the file.
 */
final class ProductImageMediaMirror
{
    /** Deadlock retries for every media-side writer that takes these locks (P9 F-7). */
    public const int ATTEMPTS = 3;

    /**
     * Locks every product image linked to the media, ascending id — call it BEFORE locking the
     * media row. The product-image actions (Crop/Update/DeleteProductImage, AttachMediaToProduct)
     * lock product → product_images → media; a media-side writer that locked media first and
     * then UPDATEd the images inverted that order (P9 audit F-7, LOAD-AND-CONCURRENCY.md).
     */
    public function lockLinkedImages(int $mediaId): void
    {
        ProductImage::query()->where('media_id', $mediaId)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    public function sync(Media $media, bool $revalidate = true): void
    {
        ProductImage::query()->where('media_id', $media->id)->update([
            'path' => $media->base_path,
            'width' => $media->width,
            'height' => $media->height,
            'processing_status' => $media->processing_status->value,
            'rendition_widths' => $media->renditions === null ? null : json_encode($media->renditionWidths(), JSON_THROW_ON_ERROR),
            'alt_text' => $media->alt_text,
            'alt_text_bn' => $media->alt_text_bn,
        ]);

        if ($revalidate) {
            $this->revalidate($media->id);
        }
    }

    /** ProductChanged for every product whose gallery uses the media. */
    public function revalidate(int $mediaId): void
    {
        $productIds = ProductImage::query()->where('media_id', $mediaId)->distinct()->pluck('product_id');

        Product::withTrashed()->whereIn('id', $productIds)->get(['id', 'slug'])
            ->each(static fn (Product $p) => ProductChanged::dispatch($p->id, $p->slug));
    }
}
