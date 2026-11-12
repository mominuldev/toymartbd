<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Services\StockOverview;
use App\Domain\Inventory\Services\StockValuation;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One row of the inventory overview: a variant at one location. Every figure is the
 * server's — `available`, `state` and `value` are never derived in the admin.
 *
 * @property ProductVariant $resource
 */
#[SchemaName('InventoryStockRow')]
final class InventoryStockRowResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $variant = $this->resource;
        $onHand = (int) $variant->getAttribute('on_hand');
        $reserved = (int) $variant->getAttribute('reserved');
        $reorderPoint = (int) $variant->getAttribute('reorder_point');
        $unitCost = StockValuation::costBasis(self::scalar($variant->getAttribute('avg_cost')), $variant->cost_price->toDecimal());
        $viewCost = InventoryVariantPart::canViewCost($request);
        /** @var list<array{location_id: int, code: string, on_hand: int}> $elsewhere */
        $elsewhere = $variant->getAttribute('elsewhere') ?? [];

        return [
            'variant_id' => $variant->id,
            'product_id' => $variant->product_id,
            'product_name' => $variant->product->name,
            /** @var string|null */
            'product_name_bn' => $variant->product->name_bn,
            /** @var string|null */
            'variant_name' => $variant->name,
            /** @var string|null */
            'variant_name_bn' => $variant->name_bn,
            'sku' => $variant->sku,
            /** @var string|null */
            'image_url' => InventoryVariantPart::imageUrl($variant),
            /** @var int */
            'on_hand' => $onHand,
            /** @var int */
            'reserved' => $reserved,
            /**
             * on_hand − reserved. May be negative only if the ledger has drifted.
             *
             * @var int
             */
            'available' => $onHand - $reserved,
            /** @var int */
            'reorder_point' => $reorderPoint,
            /**
             * `out` (available ≤ 0), `low` (0 < available ≤ reorder point) or null.
             *
             * @var 'low'|'out'|null
             */
            'state' => StockOverview::stateOf($onHand, $reserved, $reorderPoint),
            /**
             * Unit cost basis (weighted-average cost, else current cost price), 4 dp; only with catalog.view_cost.
             *
             * @var string
             */
            'unit_cost' => $this->when($viewCost, $unitCost),
            /**
             * on_hand × unit cost, 2 dp; only with catalog.view_cost.
             *
             * @var string
             */
            'value' => $this->when($viewCost, fn (): string => StockValuation::value($onHand, $unitCost)),
            /**
             * On hand at the other active locations (empty while there is only one).
             *
             * @var list<array{location_id: int, code: string, on_hand: int}>
             */
            'elsewhere' => $elsewhere,
        ];
    }

    private static function scalar(mixed $value): string|int|float|null
    {
        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }
}
