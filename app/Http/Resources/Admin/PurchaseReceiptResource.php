<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Models\PurchaseReceiptItem;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A goods-received note. Money only with catalog.view_cost.
 *
 * @property PurchaseReceipt $resource
 */
#[SchemaName('PurchaseReceipt')]
final class PurchaseReceiptResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $receipt = $this->resource;
        $viewCost = InventoryVariantPart::canViewCost($request);

        return [
            'id' => $receipt->id,
            /**
             * GRN-2026-0012.
             *
             * @var string
             */
            'number' => (string) $receipt->number,
            'purchase_id' => $receipt->purchase_id,
            'location_id' => $receipt->location_id,
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'received_at' => ProductResource::utcIso($receipt->received_at),
            /** @var 'value'|'quantity'|'weight' */
            'apportion_method' => $receipt->apportion_method->value,
            'costs_paid_to_supplier' => $receipt->costs_paid_to_supplier,
            /**
             * Who charged the landed costs when not the supplier.
             *
             * @var string|null
             */
            'costs_vendor' => $receipt->costs_vendor,
            /** @var string|null */
            'costs_reference' => $receipt->costs_reference,
            /** @var string */
            'freight' => $this->when($viewCost, fn (): string => $receipt->freight->toDecimal()),
            /** @var string */
            'customs_duty' => $this->when($viewCost, fn (): string => $receipt->customs_duty->toDecimal()),
            /** @var string */
            'other_cost' => $this->when($viewCost, fn (): string => $receipt->other_cost->toDecimal()),
            /** @var string */
            'goods_value' => $this->when($viewCost, fn (): string => $receipt->goods_value->toDecimal()),
            /** @var string */
            'payable_amount' => $this->when($viewCost, fn (): string => $receipt->payable_amount->toDecimal()),
            /** @var string|null */
            'note' => $receipt->note,
            /** @var list<array{purchase_item_id: int, variant_id: int, quantity: int, line_value?: string, allocated_cost?: string, landed_unit_cost?: string, avg_cost_before?: string, avg_cost_after?: string}> */
            'items' => $receipt->items->map(static fn (PurchaseReceiptItem $item): array => array_merge([
                'purchase_item_id' => $item->purchase_item_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
            ], $viewCost ? [
                'line_value' => $item->line_value->toDecimal(),
                'allocated_cost' => $item->allocated_cost->toDecimal(),
                'landed_unit_cost' => $item->landed_unit_cost,
                'avg_cost_before' => $item->avg_cost_before,
                'avg_cost_after' => $item->avg_cost_after,
            ] : []))->values()->all(),
        ];
    }
}
