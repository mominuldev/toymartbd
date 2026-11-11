<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductImage;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * A product image. `url` is null while the row has no file (before the media
 * pipeline, P4-CAT-07): render a placeholder.
 *
 * @property ProductImage $resource
 */
#[SchemaName('ProductImage')]
final class ProductImageResource extends ApiResource
{
    /**
     * @return array{id: int, variant_id: int|null, url: string|null, alt_text: string|null, alt_text_bn: string|null, position: int, is_primary: bool}
     */
    public function toArray(Request $request): array
    {
        $image = $this->resource;

        return [
            'id' => $image->id,
            'variant_id' => $image->variant_id,
            'url' => self::url($image->path),
            'alt_text' => $image->alt_text,
            'alt_text_bn' => $image->alt_text_bn,
            'position' => $image->position,
            'is_primary' => $image->is_primary,
        ];
    }

    public static function url(?string $path): ?string
    {
        return $path !== null && $path !== '' ? Storage::disk('public')->url($path) : null;
    }
}
