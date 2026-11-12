<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Payment\Models\Payment;
use App\Http\Resources\ApiResource;
use App\Models\Enums\Permission;
use App\Models\User;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Admin order detail (P4-ORD-05, read side). Role-based PII masking (rule 20): without
 * customers.view_pii the phone is masked and the street line, landmark and postcode are
 * withheld (`null`), leaving division/district/upazila/area — enough to triage, not to
 * visit. `pii_masked` tells the client which it got. Item cost (`cost_price_snapshot`)
 * is included only with catalog.view_cost. Money is a decimal string.
 *
 * @property Order $resource
 */
#[SchemaName('Order')]
final class OrderResource extends ApiResource
{
    public const array RELATIONS = ['items', 'payments', 'events', 'customer'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $order = $this->resource;
        $user = $request->user();
        $pii = $user instanceof User && $user->can('viewPii', Order::class);
        $cost = $user instanceof User && $user->can(Permission::CatalogViewCost->value);
        $phone = static fn (?string $e164): ?string => $e164 === null ? null : ($pii ? $e164 : (PhoneNumber::tryParse($e164)?->masked() ?? '***'));

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'fulfillment_status' => $order->fulfillment_status->value,
            'source' => $order->source->value,
            /** @var string|null */
            'payment_method' => $order->payment_method,
            'currency' => $order->currency,
            'pii_masked' => ! $pii,
            'customer' => [
                'id' => $order->customer_id,
                'name' => $order->customer->name,
                /** @var string */
                'phone' => $phone($order->customer->phone),
                'orders_count' => $order->customer->orders_count,
                'delivered_orders_count' => $order->customer->delivered_orders_count,
                'total_spent' => $order->customer->total_spent->toDecimal(),
            ],
            'shipping' => [
                'name' => $order->shipping_name,
                /** @var string */
                'phone' => $phone($order->shipping_phone),
                /** @var string|null */
                'alt_phone' => $phone($order->shipping_alt_phone),
                /** @var string|null */
                'division' => $order->shipping_division,
                /** @var string|null */
                'district' => $order->shipping_district,
                /** @var string|null */
                'upazila' => $order->shipping_upazila,
                /** @var string|null */
                'area' => $order->shipping_area,
                /** @var string|null */
                'address' => $pii ? $order->shipping_address : null,
                /** @var string|null */
                'landmark' => $pii ? $order->shipping_landmark : null,
                /** @var string|null */
                'postcode' => $pii ? $order->shipping_postcode : null,
            ],
            'totals' => [
                'subtotal' => $order->subtotal->toDecimal(),
                'discount_total' => $order->discount_total->toDecimal(),
                /** @var string|null */
                'coupon_code' => $order->coupon_code,
                'coupon_discount' => $order->coupon_discount->toDecimal(),
                'promotion_discount' => $order->promotion_discount->toDecimal(),
                'shipping_charge' => $order->shipping_charge->toDecimal(),
                'shipping_discount' => $order->shipping_discount->toDecimal(),
                'tax_total' => $order->tax_total->toDecimal(),
                'grand_total' => $order->grand_total->toDecimal(),
                'paid_total' => $order->paid_total->toDecimal(),
                'due_total' => $order->due_total->toDecimal(),
                'refunded_total' => $order->refunded_total->toDecimal(),
            ],
            'items' => $order->items->map(static fn (OrderItem $item): array => [
                'id' => $item->id,
                /** @var int|null */
                'variant_id' => $item->variant_id,
                /** @var int|null */
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                /** @var string|null */
                'product_name_bn' => $item->product_name_bn,
                /** @var string|null */
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'unit_price' => $item->unit_price->toDecimal(),
                'quantity' => $item->quantity,
                'discount' => $item->discount->toDecimal(),
                'line_total' => $item->line_total->toDecimal(),
                /** @var string|null */
                'cost_price_snapshot' => $cost ? $item->cost_price_snapshot : null,
                'quantity_shipped' => $item->quantity_shipped,
                'quantity_returned' => $item->quantity_returned,
            ])->all(),
            'payments' => $order->payments->map(static fn (Payment $p): array => [
                'id' => $p->id,
                'method' => $p->method->value,
                'status' => $p->status->value,
                'amount' => $p->amount->toDecimal(),
                'fee_amount' => $p->fee_amount->toDecimal(),
                /** @var string|null */
                'transaction_id' => $p->transaction_id,
                /** @var string|null */
                'paid_at' => $p->paid_at?->toIso8601String(),
            ])->all(),
            'timeline' => $order->events->map(static fn (OrderEvent $e): array => [
                'id' => $e->id,
                'type' => $e->type->value,
                /** @var string|null */
                'field' => $e->field,
                /** @var string|null */
                'from' => $e->from_status,
                /** @var string|null */
                'to' => $e->to_status,
                /** @var string|null */
                'description' => $e->description,
                /** @var int|null */
                'user_id' => $e->user_id,
                'is_customer_visible' => $e->is_customer_visible,
                /** @var string|null */
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'risk' => [
                /** @var int|null */
                'score' => $order->risk_score,
                /** @var string|null */
                'level' => $order->risk_level?->value,
                'verification_status' => $order->verification_status->value,
            ],
            /** @var string|null */
            'customer_note' => $order->customer_note,
            /** @var string|null */
            'internal_note' => $order->internal_note,
            /** @var string|null */
            'cancel_reason' => $order->cancel_reason?->value,
            /** @var string|null */
            'cancel_note' => $order->cancel_note,
            /** @var string|null */
            'placed_at' => $order->placed_at?->toIso8601String(),
            /** @var string|null */
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
            /** @var string|null */
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            /** @var string|null */
            'delivered_at' => $order->delivered_at?->toIso8601String(),
            /** @var string|null */
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ];
    }
}
