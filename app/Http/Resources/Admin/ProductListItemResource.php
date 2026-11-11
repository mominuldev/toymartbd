<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductVariant;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A row of the admin product list. `price_min`/`price_max` span the active variants
 * (decimal strings, computed server-side — the admin never derives a price).
 *
 * @property Product $resource
 */
#[SchemaName('ProductListItem')]
final class ProductListItemResource extends ApiResource
{
    public const array RELATIONS = ['brand', 'activeVariants', 'images'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $prices = $product->activeVariants->map(static fn (ProductVariant $v): Money => $v->price)->all();
        usort($prices, static fn (Money $a, Money $b): int => $a->compareTo($b));
        $default = $product->activeVariants->first(static fn (ProductVariant $v): bool => $v->is_default) ?? $product->activeVariants->first();
        $image = $product->images->first(static fn (ProductImage $i): bool => $i->is_primary) ?? $product->images->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            /** @var string|null */
            'name_bn' => $product->name_bn,
            'slug' => $product->slug,
            /** @var 'simple'|'variable' */
            'type' => $product->type->value,
            /** @var 'draft'|'active'|'archived' */
            'status' => $product->status->value,
            /** @var array{id: int, name: string}|null */
            'brand' => $product->brand === null ? null : ['id' => $product->brand->id, 'name' => $product->brand->name],
            'primary_category_id' => $product->primary_category_id,
            /** @var string|null */
            'sku' => $default?->sku,
            /** @var string|null */
            'price_min' => $prices === [] ? null : $prices[0]->toDecimal(),
            /** @var string|null */
            'price_max' => $prices === [] ? null : $prices[count($prices) - 1]->toDecimal(),
            'variants_count' => $product->activeVariants->count(),
            /** @var string|null */
            'image_url' => ProductImageResource::url($image?->path),
            'is_featured' => $product->is_featured,
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($product->created_at),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'updated_at' => ProductResource::utcIso($product->updated_at),
        ];
    }
}
