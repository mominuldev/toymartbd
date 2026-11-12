<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product-picker result on the create/edit order screen (P4-ORD-06/07): the variant,
 * its current price and the stock available now. The price is shown, never summed by
 * the client — totals come from `POST /orders/quote`.
 *
 * @property array{variant: ProductVariant, available: int} $resource
 */
#[SchemaName('OrderComposerVariant')]
final class OrderComposerVariantResource extends ApiResource
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
            'product_name' => $variant->product->name,
            /** @var string|null */
            'product_name_bn' => $variant->product->name_bn,
            /** @var string|null */
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'price' => $variant->price->toDecimal(),
            /** @var int */
            'available' => $this->resource['available'],
            /** @var string|null */
            'image_url' => $image instanceof ProductImage ? $image->url(160) : null,
        ];
    }
}
