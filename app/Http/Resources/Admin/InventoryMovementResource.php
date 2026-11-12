<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\StockMovement;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One row of the append-only stock ledger. `quantity` is signed; `balance_after` is the
 * location's on_hand right after this movement.
 *
 * @property StockMovement $resource
 */
#[SchemaName('InventoryMovement')]
final class InventoryMovementResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $movement = $this->resource;
        $variant = $movement->variant;
        $referenceNumber = $movement->getAttribute('reference_number');
        $userName = $movement->getAttribute('user_name');

        return [
            'id' => $movement->id,
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($movement->created_at),
            'location_id' => $movement->location_id,
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
            /** @var 'purchase_in'|'sale_out'|'sale_cancelled_in'|'customer_return_in'|'courier_return_in'|'adjustment_in'|'adjustment_out'|'damage_out'|'transfer_in'|'transfer_out'|'opening_balance' */
            'type' => $movement->type->value,
            /**
             * Signed units.
             *
             * @var int
             */
            'quantity' => $movement->quantity,
            /** @var int */
            'balance_after' => $movement->balance_after,
            /**
             * Unit cost the units moved at, 4 dp; only with catalog.view_cost.
             *
             * @var string|null
             */
            'unit_cost' => $this->when(InventoryVariantPart::canViewCost($request), $movement->unit_cost),
            /**
             * The source document: `stock_adjustment`, `product` (opening stock), and later order, purchase, shipment, refund.
             *
             * @var string|null
             */
            'reference_type' => $movement->reference_type,
            /** @var int|null */
            'reference_id' => $movement->reference_id,
            /**
             * The document's human number where it has one (ADJ-2026-0012).
             *
             * @var string|null
             */
            'reference_number' => is_string($referenceNumber) ? $referenceNumber : null,
            /**
             * Staff member who recorded it; null for the system.
             *
             * @var string|null
             */
            'user_name' => is_string($userName) ? $userName : null,
            /** @var string|null */
            'note' => $movement->note,
        ];
    }
}
