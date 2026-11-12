<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\Models\Order;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A row of the admin order board (UI-UX-DESIGN-SPEC §"Order board"): number, time,
 * customer name + **masked** phone (always — the list never shows a full number, rule 20),
 * items count, total, payment method, statuses, risk. Money is a decimal string.
 *
 * @property Order $resource
 */
#[SchemaName('OrderListItem')]
final class OrderListItemResource extends ApiResource
{
    public const array RELATIONS = [];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        return [
            'id' => $order->id,
            'number' => $order->number,
            /** @var 'pending'|'on_hold'|'confirmed'|'processing'|'ready_to_ship'|'shipped'|'delivered'|'completed'|'cancelled'|'returned'|'partially_returned' */
            'status' => $order->status->value,
            /** @var 'unpaid'|'pending'|'partially_paid'|'paid'|'partially_refunded'|'refunded'|'failed' */
            'payment_status' => $order->payment_status->value,
            /** @var 'unfulfilled'|'partially_fulfilled'|'fulfilled'|'returned' */
            'fulfillment_status' => $order->fulfillment_status->value,
            /** @var string|null */
            'payment_method' => $order->payment_method,
            /** @var 'web'|'admin'|'phone'|'facebook'|'whatsapp' */
            'source' => $order->source->value,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->shipping_name,
            'customer_phone_masked' => $order->maskedShippingPhone(),
            /** @var string|null */
            'district' => $order->shipping_district,
            /** @var int */
            'items_count' => (int) ($order->getAttribute('items_count') ?? 0),
            'grand_total' => $order->grand_total->toDecimal(),
            'due_total' => $order->due_total->toDecimal(),
            /** @var 'low'|'medium'|'high'|null */
            'risk_level' => $order->risk_level?->value,
            /** @var 'not_required'|'pending'|'verified'|'failed' */
            'verification_status' => $order->verification_status->value,
            /** @var string|null */
            'placed_at' => $order->placed_at?->toIso8601String(),
            /** @var string|null */
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
