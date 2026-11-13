<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Ordering\Models\OrderReturnItem;
use App\Domain\Payment\Models\Refund;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A return (RMA) for the Returns screen (Figma 211:5121) and the order's refund panel.
 * The customer is shown by name only — never a phone or address (rule 20). `refund` is
 * included only for staff with refunds.view. `within_window` compares the request with
 * the (PLACEHOLDER) return window after delivery.
 *
 * @property OrderReturn $resource
 */
#[SchemaName('OrderReturn')]
final class OrderReturnResource extends ApiResource
{
    public const array RELATIONS = ['order', 'items.orderItem', 'refund.items', 'refund.payment'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $return = $this->resource;
        $order = $return->order;
        $value = '0.00';
        $lines = $return->items->map(static function (OrderReturnItem $line) use (&$value): array {
            $item = $line->orderItem;
            $value = bcadd($value, bcmul($item->unit_price->toDecimal(), (string) $line->quantity, 2), 2);

            return [
                'id' => $line->id,
                'order_item_id' => $line->order_item_id,
                'product_name' => $item->product_name,
                /** @var string|null */
                'product_name_bn' => $item->product_name_bn,
                /** @var string|null */
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                /** @var string|null */
                'image_path' => $item->image_path,
                'unit_price' => $item->unit_price->toDecimal(),
                'quantity' => $line->quantity,
                /** @var int|null */
                'quantity_resellable' => $line->quantity_resellable,
                /** @var int|null */
                'quantity_damaged' => $line->quantity_damaged,
            ];
        })->all();

        $windowDays = (int) config('returns.window_days', 7);
        $refund = $return->refund;

        return [
            'id' => $return->id,
            'number' => $return->number,
            /** @var 'requested'|'received'|'inspected'|'closed'|'rejected' */
            'status' => $return->status->value,
            /** @var 'damaged_in_transit'|'defective'|'wrong_item'|'missing_parts'|'not_as_described'|'changed_mind'|'other' */
            'reason' => $return->reason->value,
            /** @var string|null */
            'reason_note' => $return->reason_note,
            'refund_shipping' => $return->refund_shipping,
            /** @var string|null */
            'note' => $return->note,
            /**
             * Σ unit price × quantity returned, for the list's VALUE column (the refund itself is `refund.amount`).
             *
             * @var string
             */
            'value' => $value,
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'customer_name' => $order->shipping_name,
                'status' => $order->status->value,
                'payment_status' => $order->payment_status->value,
                /** @var string|null */
                'payment_method' => $order->payment_method,
                /** @var string|null */
                'delivered_at' => $order->delivered_at?->toIso8601String(),
            ],
            'window_days' => $windowDays,
            /** @var bool|null */
            'within_window' => $order->delivered_at === null || $return->created_at === null
                ? null
                : $return->created_at->lessThanOrEqualTo($order->delivered_at->copy()->addDays($windowDays)->endOfDay()),
            'items' => $lines,
            /** A refund exists (shown in `refund` only with refunds.view). */
            'has_refund' => $return->refund_id !== null,
            'refund' => $refund instanceof Refund && ($request->user()?->can('viewAny', Refund::class) ?? false)
                ? new RefundResource($refund)
                : null,
            /** @var string|null */
            'received_at' => $return->received_at?->toIso8601String(),
            /** @var string|null */
            'inspected_at' => $return->inspected_at?->toIso8601String(),
            /** @var string|null */
            'closed_at' => $return->closed_at?->toIso8601String(),
            /** @var string|null */
            'created_at' => $return->created_at?->toIso8601String(),
            'can' => [
                'manage' => $request->user()?->can('update', $return) ?? false,
            ],
        ];
    }
}
