<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A grid tile: the variant, its current price (shown, never summed by the register — totals
 * come from the quote) and what is available at the session's branch ("7 here").
 *
 * @property array{variant: ProductVariant, available: int} $resource
 */
#[SchemaName('PosCatalogItem')]
final class PosCatalogItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->resource['variant'];
        $images = $variant->product->images->sortBy('position');
        $image = $images->firstWhere('variant_id', $variant->id) ?? $images->firstWhere('is_primary', true) ?? $images->first();

        return [
            /** @var int */
            'variant_id' => $variant->id,
            /** @var int */
            'product_id' => $variant->product_id,
            'name' => $variant->product->name,
            /** @var string|null */
            'name_bn' => $variant->product->name_bn,
            /** @var string|null */
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            /** @var string|null */
            'barcode' => $variant->barcode,
            'price' => $variant->price->toDecimal(),
            /**
             * Available at this branch now (on hand − reserved).
             *
             * @var int
             */
            'available' => $this->resource['available'],
            /** @var string|null */
            'image_url' => $image instanceof ProductImage ? $image->url(320) : null,
        ];
    }
}
