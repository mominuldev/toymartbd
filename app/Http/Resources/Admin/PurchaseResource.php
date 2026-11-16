<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Services\PurchaseTotals;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use App\Support\Money;
use Closure;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A PO with its lines and receipts. Every figure is server-computed (rule 1); money is
 * present only with catalog.view_cost. `can` lists the moves the state machine allows
 * now, so the admin never re-derives the lifecycle.
 *
 * @property Purchase $resource
 */
#[SchemaName('Purchase')]
final class PurchaseResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $purchase = $this->resource;
        $viewCost = InventoryVariantPart::canViewCost($request);
        $received = $purchase->items->sum('quantity_received');
        $money = static fn (string $field): Closure => static fn (): string => $purchase->{$field}->toDecimal();

        return [
            'id' => $purchase->id,
            /** @var string */
            'number' => (string) $purchase->number,
            /** @var 'draft'|'ordered'|'partially_received'|'received'|'cancelled'|'closed_short' */
            'status' => $purchase->status->value,
            'supplier' => [
                'id' => $purchase->supplier->id,
                'name' => $purchase->supplier->name,
                'payment_terms_days' => $purchase->supplier->payment_terms_days,
                /** @var 'active'|'archived' */
                'status' => $purchase->supplier->status->value,
            ],
            'location_id' => $purchase->location_id,
            /** @var string */
            'order_date' => $purchase->order_date->toDateString(),
            /** @var string|null */
            'expected_date' => $purchase->expected_date?->toDateString(),
            /**
             * @var string|null
             *
             * @format date-time
             */
            'ordered_at' => $purchase->ordered_at === null ? null : ProductResource::utcIso($purchase->ordered_at),
            /**
             * @var string|null
             *
             * @format date-time
             */
            'received_at' => $purchase->received_at === null ? null : ProductResource::utcIso($purchase->received_at),
            /**
             * @var string|null
             *
             * @format date-time
             */
            'cancelled_at' => $purchase->cancelled_at === null ? null : ProductResource::utcIso($purchase->cancelled_at),
            /**
             * When a partly received PO was closed short.
             *
             * @var string|null
             *
             * @format date-time
             */
            'closed_at' => $purchase->closed_at === null ? null : ProductResource::utcIso($purchase->closed_at),
            /** @var int|null */
            'closed_by' => $purchase->closed_by,
            /** @var string|null */
            'close_reason' => $purchase->close_reason,
            /** @var string|null */
            'supplier_invoice_no' => $purchase->supplier_invoice_no,
            /** @var string|null */
            'note' => $purchase->note,
            /** @var string */
            'subtotal' => $this->when($viewCost, $money('subtotal')),
            /** @var string */
            'discount' => $this->when($viewCost, $money('discount')),
            /**
             * Header column, legacy: receipts before 2026-11-16 added their extras here. Read `extra_costs`.
             *
             * @var string
             */
            'shipping_cost' => $this->when($viewCost, $money('shipping_cost')),
            /**
             * Header column, legacy: receipts before 2026-11-16 added their extras here. Read `extra_costs`.
             *
             * @var string
             */
            'customs_duty' => $this->when($viewCost, $money('customs_duty')),
            /**
             * Header column, legacy: receipts before 2026-11-16 added their extras here. Read `extra_costs`.
             *
             * @var string
             */
            'other_cost' => $this->when($viewCost, $money('other_cost')),
            /** @var string */
            'tax' => $this->when($viewCost, $money('tax')),
            /**
             * The order total: what was ordered (goods less discounts, plus tax). Extra costs entered
             * at receipt are never in it — see `extra_costs`.
             *
             * @var string
             */
            'total' => $this->when($viewCost, fn (): string => PurchaseTotals::ordered($purchase)->toDecimal()),
            /**
             * Extra costs entered at receipt, summed from the receipts: each kind, their total, the part
             * the supplier charged (in what is owed) and the part paid to someone else (never owed to it).
             *
             * @var array{freight: string, customs_duty: string, other: string, total: string, charged_by_supplier: string, paid_elsewhere: string}
             */
            'extra_costs' => $this->when($viewCost, fn (): array => array_map(static fn (Money $m): string => $m->toDecimal(), PurchaseTotals::extras($purchase))),
            /**
             * Goods received so far at the supplier's price (Σ receipts' goods value).
             *
             * @var string
             */
            'received_goods' => $this->when($viewCost, fn (): string => PurchaseTotals::receivedGoods($purchase)->toDecimal()),
            /**
             * What the supplier is owed for so far: received goods + extra costs it charged. `due_amount` = this − `paid_amount`.
             *
             * @var string
             */
            'payable_total' => $this->when($viewCost, $money('received_value')),
            /**
             * Same as `payable_total` (kept for existing clients).
             *
             * @var string
             */
            'received_value' => $this->when($viewCost, $money('received_value')),
            /** @var string */
            'paid_amount' => $this->when($viewCost, $money('paid_amount')),
            /** @var string */
            'due_amount' => $this->when($viewCost, $money('due_amount')),
            /** Waiting for stock and past its expected date (Dhaka today). */
            'is_late' => $purchase->isLate(DhakaDate::today()),
            /** Something was received and paid, and nothing is owed on it. catalog.view_cost only. */
            'is_paid' => $this->when($viewCost, fn (): bool => $purchase->isPaid()),
            /**
             * When what is owed falls due: the first delivery's Dhaka date plus the supplier's
             * payment terms; null while nothing is owed. catalog.view_cost only.
             *
             * @var string|null
             */
            'payment_due_date' => $this->when($viewCost, fn (): ?string => self::dueDate($purchase)),
            'units_ordered' => (int) $purchase->items->sum('quantity_ordered'),
            'units_received' => (int) $received,
            /** @var array{edit: bool, place: bool, cancel: bool, receive: bool, close: bool} */
            'can' => [
                'edit' => $purchase->status->isEditable(),
                'place' => $purchase->status->canTransitionTo(PurchaseStatus::Ordered),
                'cancel' => $received === 0 && $purchase->status->canTransitionTo(PurchaseStatus::Cancelled),
                'receive' => $purchase->status->canReceive(),
                'close' => $purchase->status->canTransitionTo(PurchaseStatus::ClosedShort),
            ],
            'items' => PurchaseLineResource::collection($purchase->items),
            'receipts' => PurchaseReceiptResource::collection($purchase->receipts),
            /**
             * @var string
             *
             * @format date-time
             */
            'created_at' => ProductResource::utcIso($purchase->created_at),
        ];
    }

    private static function dueDate(Purchase $purchase): ?string
    {
        $first = $purchase->receipts->first();
        if ($first === null || ! $purchase->due_amount->greaterThan(Money::zero())) {
            return null;
        }

        return DhakaDate::local($first->received_at)->startOfDay()->addDays($purchase->supplier->payment_terms_days)->toDateString();
    }
}
