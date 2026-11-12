<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A recorded stock adjustment and its lines.
 *
 * @property StockAdjustment $resource
 */
#[SchemaName('InventoryAdjustment')]
final class InventoryAdjustmentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $adjustment = $this->resource;
        $viewCost = InventoryVariantPart::canViewCost($request);

        return [
            'id' => $adjustment->id,
            /**
             * ADJ-2026-0012.
             *
             * @var string
             */
            'number' => (string) $adjustment->number,
            'location_id' => $adjustment->location_id,
            /** @var 'damage'|'loss'|'found'|'recount'|'expiry'|'correction'|'gift_sample' */
            'reason' => $adjustment->reason->value,
            /** @var string|null */
            'note' => $adjustment->note,
            /** @var 'applied' */
            'status' => $adjustment->status->value,
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($adjustment->created_at),
            /** @var list<array{variant_id: int, quantity_before: int, quantity_after: int, change: int, unit_cost?: string|null}> */
            'items' => $adjustment->items->map(static fn (StockAdjustmentItem $item): array => array_merge([
                'variant_id' => $item->variant_id,
                'quantity_before' => $item->quantity_before,
                'quantity_after' => $item->quantity_after,
                'change' => $item->change(),
            ], $viewCost ? ['unit_cost' => $item->unit_cost] : []))->values()->all(),
        ];
    }
}
