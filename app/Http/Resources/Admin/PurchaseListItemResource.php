<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\Models\Purchase;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A PO row in the list (Figma "16 Purchase orders"). Money only with catalog.view_cost.
 *
 * @property Purchase $resource
 */
#[SchemaName('PurchaseListItem')]
final class PurchaseListItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $purchase = $this->resource;
        $viewCost = InventoryVariantPart::canViewCost($request);

        return [
            'id' => $purchase->id,
            /**
             * PO-2026-0044.
             *
             * @var string
             */
            'number' => (string) $purchase->number,
            /** @var 'draft'|'ordered'|'partially_received'|'received'|'cancelled'|'closed_short' */
            'status' => $purchase->status->value,
            'supplier' => [
                'id' => $purchase->supplier->id,
                'name' => $purchase->supplier->name,
                'payment_terms_days' => $purchase->supplier->payment_terms_days,
            ],
            'location_id' => $purchase->location_id,
            /**
             * Y-m-d.
             *
             * @var string
             */
            'order_date' => $purchase->order_date->toDateString(),
            /** @var string|null */
            'expected_date' => $purchase->expected_date?->toDateString(),
            /** @var int */
            'lines_count' => (int) ($purchase->getAttribute('items_count') ?? $purchase->items()->count()),
            /** @var string */
            'total' => $this->when($viewCost, fn (): string => $purchase->total->toDecimal()),
            /** @var string */
            'due_amount' => $this->when($viewCost, fn (): string => $purchase->due_amount->toDecimal()),
            /**
             * ISO 8601, UTC.
             *
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($purchase->created_at),
        ];
    }
}
