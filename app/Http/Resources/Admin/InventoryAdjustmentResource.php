<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A stock adjustment, its approval state and its lines.
 *
 * @property StockAdjustment $resource
 */
#[SchemaName('InventoryAdjustment')]
final class InventoryAdjustmentResource extends ApiResource
{
    /** Relations the resource reads; eager-load them for a list. */
    public const array RELATIONS = ['items.variant.product.images', 'requester', 'approver', 'rejecter', 'location'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $adjustment = $this->resource;
        $adjustment->loadMissing(self::RELATIONS);
        $viewCost = InventoryVariantPart::canViewCost($request);
        $iso = static fn ($at): ?string => $at === null ? null : ProductResource::utcIso($at);

        return [
            'id' => $adjustment->id,
            /**
             * ADJ-2026-0012.
             *
             * @var string
             */
            'number' => (string) $adjustment->number,
            'location_id' => $adjustment->location_id,
            'location_code' => $adjustment->location->code,
            /** @var 'damage'|'loss'|'theft'|'found'|'recount'|'expiry'|'correction'|'gift_sample' */
            'reason' => $adjustment->reason->value,
            /** @var string|null */
            'note' => $adjustment->note,
            /**
             * `applied` (below the approval threshold, moved at once), `pending` (waiting for
             * approval, nothing moved), `approved` (moved when approved), `rejected` (never moved).
             *
             * @var 'applied'|'pending'|'approved'|'rejected'
             */
            'status' => $adjustment->status->value,
            /** Whether this adjustment has written its stock movements. */
            'moved_stock' => $adjustment->status->movedStock(),
            /**
             * Units the request changes (absolute, summed) — what the approval threshold reads.
             *
             * @var int
             */
            'requested_units' => $adjustment->requested_units,
            /**
             * Their value at weighted-average cost, 2 dp; only with catalog.view_cost.
             *
             * @var string
             */
            'requested_value' => $this->when($viewCost, $adjustment->requested_value->toDecimal()),
            /** @var string|null */
            'requested_by' => $adjustment->requester?->name,
            /** @var string|null */
            'approved_by' => $adjustment->approver?->name,
            /**
             * @var string|null
             *
             * @format date-time
             */
            'approved_at' => $iso($adjustment->approved_at),
            /** @var string|null */
            'rejected_by' => $adjustment->rejecter?->name,
            /**
             * @var string|null
             *
             * @format date-time
             */
            'rejected_at' => $iso($adjustment->rejected_at),
            /** @var string|null */
            'decision_note' => $adjustment->decision_note,
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($adjustment->created_at),
            /**
             * One per variant. `quantity_before`/`after` and `change` are provisional while pending
             * (re-read under the lock on approval) and final once moved.
             *
             * @var list<array{variant_id: int, sku: string, product_name: string, product_name_bn: string|null, variant_name: string|null, image_url: string|null, requested_change: int|null, counted: int|null, quantity_before: int, quantity_after: int, change: int, unit_cost?: string|null}>
             */
            'items' => $adjustment->items->map(static fn (StockAdjustmentItem $item): array => array_merge([
                'variant_id' => $item->variant_id,
                'sku' => $item->variant->sku,
                'product_name' => $item->variant->product->name,
                'product_name_bn' => $item->variant->product->name_bn,
                'variant_name' => $item->variant->name,
                'image_url' => InventoryVariantPart::imageUrl($item->variant),
                'requested_change' => $item->requested_change,
                'counted' => $item->counted,
                'quantity_before' => $item->quantity_before,
                'quantity_after' => $item->quantity_after,
                'change' => $item->change(),
            ], $viewCost ? ['unit_cost' => $item->unit_cost] : []))->values()->all(),
        ];
    }
}
