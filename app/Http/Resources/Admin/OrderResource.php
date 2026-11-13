<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Ordering\Actions\EditOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderNote;
use App\Domain\Ordering\Queries\OrderAnomalies;
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
        $notes = OrderNote::query()->where('order_id', $order->id)->orderBy('id')->get();
        $names = self::actorNames([...$order->events->pluck('user_id')->all(), ...$notes->pluck('user_id')->all()]);

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
            /**
             * Why this status combination should not exist, or null (spec A12).
             *
             * @var 'delivered_unpaid'|'refund_pending'|'goods_before_shipping'|'shipped_unfulfilled'|'payment_failed'|null
             */
            'anomaly' => OrderAnomalies::reason($order),
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
                /** @var int|null */
                'division_id' => $order->shipping_division_id,
                /** @var int|null */
                'district_id' => $order->shipping_district_id,
                /** @var int|null */
                'upazila_id' => $order->shipping_upazila_id,
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
                /** Online gateway ids and state (P4-PAY-02/03); null/false for COD. @var string|null */
                'gateway_reference' => $p->gateway_reference,
                /** @var string|null */
                'val_id' => is_string($p->gateway_payload['val_id'] ?? null) ? $p->gateway_payload['val_id'] : null,
                /** @var string|null */
                'failure_reason' => $p->failure_reason,
                /** @var string|null */
                'review_reason' => $p->review_reason,
                /** @var string|null */
                'expires_at' => $p->expires_at?->toIso8601String(),
                /** @var string|null */
                'verified_at' => $p->verified_at?->toIso8601String(),
                /** @var array{enabled: bool, verified: bool, sandbox: bool, reason: string|null}|null */
                'gateway' => $p->method->isOnline() ? self::gateway($p->method) : null,
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
                /** @var string|null */
                'user_name' => $e->user_id === null ? null : ($names[$e->user_id] ?? null),
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
                /**
                 * Our advisory signals as stored at scoring time — never a courier's data (rule 24).
                 *
                 * @var array<string, mixed>|null
                 */
                'flags' => $order->risk_flags,
                /**
                 * The phone's CURRENT profile (P6 risk module): our score now and the staff list
                 * status — beside, never merged with, the snapshot above (rule 24).
                 *
                 * @var array<string, mixed>|null
                 */
                'profile' => self::riskProfile($order->shipping_phone),
            ],
            'notes' => $notes->map(static fn (OrderNote $n): array => [
                'id' => $n->id,
                'body' => $n->body,
                'is_customer_visible' => $n->is_customer_visible,
                /** @var int|null */
                'user_id' => $n->user_id,
                /** @var string|null */
                'user_name' => $n->user_id === null ? null : ($names[$n->user_id] ?? null),
                /** @var string|null */
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            /**
             * What this user may do to the order now — the state machine and their permissions,
             * decided here so the client never re-derives a transition rule.
             *
             * @var list<'confirm'|'hold'|'release'|'processing'|'ready_to_ship'|'ship'|'deliver'|'cancel'|'add_note'>
             */
            'available_actions' => self::availableActions($order, $user instanceof User ? $user : null),
            /** Whether this user may edit the order now (P4-ORD-07: pre-packing status and orders.update). */
            'editable' => $user instanceof User && EditOrder::isEditable($order) && $user->can('update', $order),
            /** Whether this user may print the invoice / packing slip (orders.view + customers.view_pii). */
            'can_print_invoice' => $user instanceof User && $user->can('printInvoices', Order::class),
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

    /** @return list<string> */
    private static function availableActions(Order $order, ?User $user): array
    {
        if ($user === null) {
            return [];
        }
        $status = $order->status;
        $actions = [
            'confirm' => $status->canTransitionTo(OrderStatus::Confirmed) && $user->can('confirm', $order),
            'hold' => $status->canTransitionTo(OrderStatus::OnHold) && $user->can('progress', $order),
            'release' => $status === OrderStatus::OnHold && $user->can('progress', $order),
            'processing' => $status->canTransitionTo(OrderStatus::Processing) && $user->can('progress', $order),
            'ready_to_ship' => $status->canTransitionTo(OrderStatus::ReadyToShip) && $user->can('progress', $order),
            // Manual fulfilment (P5) until Phase 6 courier booking.
            'ship' => $status->canTransitionTo(OrderStatus::Shipped) && $user->can('fulfil', $order),
            'deliver' => $status->canTransitionTo(OrderStatus::Delivered) && $user->can('fulfil', $order),
            'cancel' => $status->isCancellable() && $user->can('cancel', $order),
            'add_note' => $user->can('addNote', $order),
        ];

        return array_keys(array_filter($actions));
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return array<int, string> user id → display name (staff names only, no PII)
     */
    private static function actorNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null)));
        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> */
        return User::query()->whereKey($ids)->pluck('name', 'id')->all();
    }

    /** @return array{enabled: bool, verified: bool, sandbox: bool, reason: string|null} */
    private static function gateway(\App\Domain\Payment\Enums\PaymentMethod $method): array
    {
        $status = app(\App\Domain\Payment\Services\OnlineGateways::class)->status($method);

        return ['enabled' => $status['enabled'], 'verified' => $status['verified'], 'sandbox' => $status['sandbox'], 'reason' => $status['reason']];
    }

    /** @return array<string, mixed>|null */
    private static function riskProfile(string $phone): ?array
    {
        $profile = \App\Domain\Risk\Models\CustomerRiskProfile::forPhone($phone);
        if ($profile === null) {
            return null;
        }
        $ours = Risk\RiskProfilePresenter::ours($profile);

        return [
            'internal_score' => $ours['internal_score'],
            'level' => $ours['level'],
            'score_computed_at' => $ours['score_computed_at'],
            'counts' => $ours['counts'],
            'status' => $profile->status->value,
            'status_reason' => $profile->status_reason,
            'status_set_at' => $profile->status_set_at?->toIso8601String(),
        ];
    }
}
