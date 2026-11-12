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
        $reorderQuantity = (int) $variant->getAttribute('reorder_quantity');
        $state = StockOverview::stateOf($onHand, $reserved, $reorderPoint, $reorderQuantity);
        $unitCost = StockValuation::costBasis(self::scalar($variant->getAttribute('avg_cost')), $variant->cost_price->toDecimal());
        $viewCost = InventoryVariantPart::canViewCost($request);
        /** @var list<array{location_id: int, code: string, on_hand: int, available: int}> $elsewhere */
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
             * Units a reorder brings in; 0 = not set (the variant is then never overstock).
             *
             * @var int
             */
            'reorder_quantity' => $reorderQuantity,
            /**
             * `out` (available ≤ 0), `low` (0 < available ≤ reorder point), `overstock`
             * (reorder quantity set and available > reorder point + 2 × reorder quantity) or null.
             *
             * @var 'low'|'out'|'overstock'|null
             */
            'state' => $state,
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
             * On hand and available at every other active location, in location order
             * (empty while there is only one location).
             *
             * @var list<array{location_id: int, code: string, on_hand: int, available: int}>
             */
            'elsewhere' => $elsewhere,
            /**
             * For a low or out-of-stock row: the other location with the most units
             * available, the source a transfer would draw from; null when no other
             * location has any, or the row is not short.
             *
             * @var array{location_id: int, code: string, available: int}|null
             */
            'transfer_from' => in_array($state, [StockOverview::LOW, StockOverview::OUT], true) ? self::transferSource($elsewhere) : null,
        ];
    }

    /**
     * @param  list<array{location_id: int, code: string, on_hand: int, available: int}>  $elsewhere
     * @return array{location_id: int, code: string, available: int}|null
     */
    private static function transferSource(array $elsewhere): ?array
    {
        $best = null;
        foreach ($elsewhere as $location) {
            if ($location['available'] > 0 && ($best === null || $location['available'] > $best['available'])) {
                $best = ['location_id' => $location['location_id'], 'code' => $location['code'], 'available' => $location['available']];
            }
        }

        return $best;
    }

    private static function scalar(mixed $value): string|int|float|null
    {
        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }
}
