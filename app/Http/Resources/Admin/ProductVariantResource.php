<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\Margins;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockAvailability;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A variant in the admin. Money is a decimal string ("1250.00"). `cost_price` is
 * present only for staff with catalog.view_cost.
 *
 * @property ProductVariant $resource
 */
#[SchemaName('ProductVariant')]
final class ProductVariantResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $variant = $this->resource;

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            /** @var string|null */
            'barcode' => $variant->barcode,
            /** @var string|null */
            'name' => $variant->name,
            /** @var string|null */
            'name_bn' => $variant->name_bn,
            /**
             * Selling price, decimal string.
             *
             * @var string
             */
            'price' => $variant->price->toDecimal(),
            /** @var string|null */
            'compare_at_price' => $variant->compare_at_price?->toDecimal(),
            /**
             * Current replacement cost; only with catalog.view_cost.
             *
             * @var string
             */
            'cost_price' => $this->when(self::canViewCost($request), fn (): string => $variant->cost_price->toDecimal()),
            /**
             * Per-unit margin at this price and cost, VAT taken out, before delivery and COD
             * costs — computed by the server. Only with catalog.view_cost; null when the
             * price or the cost is not set.
             *
             * @var array{percent: string, profit: string, net_price: string}|null
             */
            'margin' => $this->when(self::canViewCost($request), fn (): ?array => self::marginFor($variant)),
            /** @var string|null */
            'wholesale_price' => $variant->wholesale_price?->toDecimal(),
            'weight_grams' => $variant->weight_grams,
            /**
             * Current stock at all locations; only with inventory.view. Read-only here:
             * opening stock is set on create (`opening_stock`), later changes are
             * stock adjustments (Phase 5).
             *
             * @var array{on_hand: int, reserved: int, available: int}
             */
            'stock' => $this->when(self::canViewStock($request), fn (): array => self::stockFor($request, $variant)),
            'is_default' => $variant->is_default,
            'position' => $variant->position,
            /** @var 'active'|'archived' */
            'status' => $variant->status->value,
            /**
             * One value per variant-defining attribute; empty for a simple product.
             *
             * @var list<array{attribute_id: int, attribute_value_id: int}>
             */
            'attribute_values' => $variant->relationLoaded('attributeValues')
                ? $variant->attributeValues->map(static fn (ProductAttributeValue $value): array => [
                    'attribute_id' => $value->attribute_id,
                    'attribute_value_id' => $value->id,
                ])->values()->all()
                : [],
        ];
    }

    public static function canViewStock(Request $request): bool
    {
        return $request->user()?->can('viewAny', StockLevel::class) ?? false;
    }

    /**
     * One query per product per request: the first variant loads every sibling's level.
     *
     * @return array{on_hand: int, reserved: int, available: int}
     */
    private static function stockFor(Request $request, ProductVariant $variant): array
    {
        $key = 'admin.stock.product.'.$variant->product_id;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(StockAvailability::class)->levelsForProduct($variant->product_id));
        }

        /** @var array<int, array{on_hand: int, reserved: int, available: int}> $levels */
        $levels = $request->attributes->get($key);

        return $levels[$variant->id] ?? ['on_hand' => 0, 'reserved' => 0, 'available' => 0];
    }

    /** @return array{percent: string, profit: string, net_price: string}|null */
    public static function marginFor(ProductVariant $variant): ?array
    {
        $vat = $variant->relationLoaded('product') ? $variant->product->vat_rate : (string) Product::query()->whereKey($variant->product_id)->value('vat_rate');

        return app(Margins::class)->forUnit($variant->price, $variant->cost_price, $vat !== '' ? $vat : '0')?->toArray();
    }

    public static function canViewCost(Request $request): bool
    {
        return $request->user()?->can('viewCost', Product::class) ?? false;
    }
}
