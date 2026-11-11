<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductImage;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product image (P4-CAT-06/07). `urls` and `srcset` are null while the image has
 * no file (seeded rows) or its renditions are still `processing`: render a
 * placeholder of `width` × `height` (when known) so nothing reflows.
 *
 * `url` is the large WebP rendition, kept for earlier consumers.
 *
 * @property ProductImage $resource
 */
#[SchemaName('ProductImage')]
final class ProductImageResource extends ApiResource
{
    /**
     * @return array{id: int, variant_id: int|null, url: string|null, urls: array{thumb: string|null, medium: string|null, large: string|null}, srcset: array{webp: string|null, avif: string|null}, width: int|null, height: int|null, processing_status: 'processing'|'ready'|'failed', alt_text: string|null, alt_text_bn: string|null, position: int, is_primary: bool}
     */
    public function toArray(Request $request): array
    {
        $image = $this->resource;
        /** @var array{thumb: int, medium: int, large: int} $sizes */
        $sizes = config('media.sizes');

        return [
            'id' => $image->id,
            'variant_id' => $image->variant_id,
            'url' => $image->url($sizes['large']),
            'urls' => [
                'thumb' => $image->url($sizes['thumb']),
                'medium' => $image->url($sizes['medium']),
                'large' => $image->url($sizes['large']),
            ],
            'srcset' => [
                'webp' => $image->srcset('webp'),
                'avif' => $image->srcset('avif'),
            ],
            'width' => $image->width,
            'height' => $image->height,
            /** @var 'processing'|'ready'|'failed' */
            'processing_status' => $image->processing_status->value,
            'alt_text' => $image->alt_text,
            'alt_text_bn' => $image->alt_text_bn,
            'position' => $image->position,
            'is_primary' => $image->is_primary,
        ];
    }
}
