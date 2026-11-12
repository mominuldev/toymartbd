<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferItem;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A stock transfer between two locations and its lines.
 *
 * @property StockTransfer $resource
 */
#[SchemaName('InventoryTransfer')]
final class InventoryTransferResource extends ApiResource
{
    public const array RELATIONS = ['items.variant.product.images', 'fromLocation', 'toLocation', 'user'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $transfer = $this->resource;
        $transfer->loadMissing(self::RELATIONS);
        $viewCost = InventoryVariantPart::canViewCost($request);

        return [
            'id' => $transfer->id,
            /**
             * TRF-2026-0003.
             *
             * @var string
             */
            'number' => (string) $transfer->number,
            'from_location_id' => $transfer->from_location_id,
            'from_location_code' => $transfer->fromLocation->code,
            'to_location_id' => $transfer->to_location_id,
            'to_location_code' => $transfer->toLocation->code,
            /**
             * Booked in one step: no in-transit state.
             *
             * @var 'completed'
             */
            'status' => $transfer->status->value,
            /** @var string|null */
            'note' => $transfer->note,
            /** @var string|null */
            'user_name' => $transfer->user?->name,
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($transfer->created_at),
            /** @var list<array{variant_id: int, sku: string, product_name: string, product_name_bn: string|null, variant_name: string|null, quantity: int, unit_cost?: string|null}> */
            'items' => $transfer->items->map(static fn (StockTransferItem $item): array => array_merge([
                'variant_id' => $item->variant_id,
                'sku' => $item->variant->sku,
                'product_name' => $item->variant->product->name,
                'product_name_bn' => $item->variant->product->name_bn,
                'variant_name' => $item->variant->name,
                'quantity' => $item->quantity,
            ], $viewCost ? ['unit_cost' => $item->unit_cost] : []))->values()->all(),
        ];
    }
}
