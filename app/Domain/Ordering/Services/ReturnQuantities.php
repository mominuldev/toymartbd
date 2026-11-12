<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * What can still come back on an order, computed server-side (rule 1):
 *
 *   returnable = delivered − quantity_returned − held by open (requested / received) returns
 *
 * "Delivered" is the line quantity: a return is only accepted on a delivered or partially
 * returned order, and units the courier brought back are already in quantity_returned.
 */
final class ReturnQuantities
{
    /** @return array<int, int> order item id → units still returnable */
    public function returnable(Order $order, ?int $exceptReturnId = null): array
    {
        $held = DB::table('order_return_items')
            ->join('order_returns', 'order_returns.id', '=', 'order_return_items.order_return_id')
            ->where('order_returns.order_id', $order->id)
            ->whereIn('order_returns.status', array_map(static fn (ReturnStatus $s): string => $s->value, array_filter(ReturnStatus::cases(), static fn (ReturnStatus $s): bool => $s->isOpen())))
            ->when($exceptReturnId !== null, static fn ($q) => $q->where('order_returns.id', '!=', $exceptReturnId))
            ->groupBy('order_return_items.order_item_id')
            ->selectRaw('order_return_items.order_item_id as item_id, SUM(order_return_items.quantity) as held')
            ->pluck('held', 'item_id');

        $returnable = [];
        foreach ($order->items as $item) {
            $returnable[$item->id] = max(0, $item->quantity - $item->quantity_returned - (int) ($held[$item->id] ?? 0));
        }

        return $returnable;
    }

    /** True when the delivery charge is already refunded, or claimed by an open return. */
    public function shippingClaimed(int $orderId): bool
    {
        $refunded = Refund::query()->where('order_id', $orderId)
            ->where('status', '!=', RefundStatus::Rejected->value)
            ->where('shipping_amount', '>', 0)
            ->exists();

        return $refunded || DB::table('order_returns')->where('order_id', $orderId)
            ->whereIn('status', [ReturnStatus::Requested->value, ReturnStatus::Received->value])
            ->where('refund_shipping', true)
            ->exists();
    }

    /**
     * Money that may still be refunded: captured (paid payments) minus every refund not
     * rejected — requested and approved ones included, so two refunds in flight can never
     * add up to more than was taken.
     */
    public function refundable(int $orderId, ?int $exceptRefundId = null): Money
    {
        $captured = $this->captured($orderId);
        $claimed = Refund::query()->where('order_id', $orderId)
            ->where('status', '!=', RefundStatus::Rejected->value)
            ->when($exceptRefundId !== null, static fn ($q) => $q->whereKeyNot($exceptRefundId))
            ->get(['amount'])
            ->reduce(static fn (Money $c, Refund $r): Money => $c->plus($r->amount), Money::zero());

        $left = $captured->minus($claimed);

        return $left->isNegative() ? Money::zero() : $left;
    }

    /** Σ paid payments of the order. */
    public function captured(int $orderId): Money
    {
        return Payment::query()->where('order_id', $orderId)->where('status', PaymentStatus::Paid->value)
            ->get(['amount'])
            ->reduce(static fn (Money $c, Payment $p): Money => $c->plus($p->amount), Money::zero());
    }

    /** The payment a refund goes back through: the latest paid one. */
    public function capturedPayment(int $orderId): ?Payment
    {
        return Payment::query()->where('order_id', $orderId)->where('status', PaymentStatus::Paid->value)
            ->orderByDesc('id')->first();
    }
}
