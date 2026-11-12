<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Status combinations that should not exist (admin spec A12 "anomaly" list) — the board's
 * Anomalies tab and the red-edged money/goods chip. One definition serves the SQL filter
 * and the per-row reason, so the tab count and the chips always agree.
 *
 * Covered now: delivered/completed but not paid (a partial refund on a delivered order is
 * only `notable` in A12, not an anomaly) · cancelled with money still owed back ·
 * goods moved before shipping · shipped with nothing fulfilled · payment failed.
 * Not yet: "on hold over 48 h" needs a hold timestamp, and "paid but unsettled" needs
 * courier settlements (Phase 6).
 */
final class OrderAnomalies
{
    public const string DELIVERED_UNPAID = 'delivered_unpaid';

    public const string REFUND_PENDING = 'refund_pending';

    public const string GOODS_BEFORE_SHIPPING = 'goods_before_shipping';

    public const string SHIPPED_UNFULFILLED = 'shipped_unfulfilled';

    public const string PAYMENT_FAILED = 'payment_failed';

    private const array PRE_SHIP = [OrderStatus::Pending, OrderStatus::OnHold, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip];

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  an `orders` query
     */
    public static function apply(Builder $query): void
    {
        $values = static fn (array $cases): array => array_map(static fn (OrderStatus $s): string => $s->value, $cases);

        $query->where(static function (Builder $q) use ($values): void {
            $q->where(static fn (Builder $w) => $w->whereIn('status', $values([OrderStatus::Delivered, OrderStatus::Completed]))
                ->whereNotIn('payment_status', [OrderPaymentStatus::Paid->value, OrderPaymentStatus::PartiallyRefunded->value]))
                ->orWhere(static fn (Builder $w) => $w->where('status', OrderStatus::Cancelled->value)
                    ->whereColumn('paid_total', '>', 'refunded_total'))
                ->orWhere(static fn (Builder $w) => $w->whereIn('status', $values(self::PRE_SHIP))
                    ->where('fulfillment_status', '!=', FulfillmentStatus::Unfulfilled->value))
                ->orWhere(static fn (Builder $w) => $w->where('status', OrderStatus::Shipped->value)
                    ->where('fulfillment_status', FulfillmentStatus::Unfulfilled->value))
                ->orWhere('payment_status', OrderPaymentStatus::Failed->value);
        });
    }

    /** The first anomaly that applies to this order, or null for a normal one. Same rules as {@see apply()}. */
    public static function reason(Order $order): ?string
    {
        $status = $order->status;

        return match (true) {
            in_array($status, [OrderStatus::Delivered, OrderStatus::Completed], true) && ! in_array($order->payment_status, [OrderPaymentStatus::Paid, OrderPaymentStatus::PartiallyRefunded], true) => self::DELIVERED_UNPAID,
            $status === OrderStatus::Cancelled && $order->paid_total->greaterThan($order->refunded_total) => self::REFUND_PENDING,
            in_array($status, self::PRE_SHIP, true) && $order->fulfillment_status !== FulfillmentStatus::Unfulfilled => self::GOODS_BEFORE_SHIPPING,
            $status === OrderStatus::Shipped && $order->fulfillment_status === FulfillmentStatus::Unfulfilled => self::SHIPPED_UNFULFILLED,
            $order->payment_status === OrderPaymentStatus::Failed => self::PAYMENT_FAILED,
            default => null,
        };
    }
}
