<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderPaymentStatusResolver;
use App\Domain\Ordering\Services\OrderTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Re-derive an order's payment_status and paid/due/refunded totals from its payments and
 * refunds (after a PaymentStatusChanged). A full recomputation, so a replayed event is a
 * no-op; a move the state machine refuses throws — that is a bug to see, not to hide.
 */
final readonly class SyncOrderPaymentStatus
{
    public function __construct(
        private OrderTransitions $transitions,
        private OrderPaymentStatusResolver $resolver,
    ) {}

    public function handle(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId): Order {
            /** @var Order $order */
            $order = Order::query()->with(['payments', 'refunds'])->lockForUpdate()->findOrFail($orderId);
            $derived = $this->resolver->resolve($order);

            $totals = [
                'paid_total' => $derived->paid,
                'refunded_total' => $derived->refunded,
                // §7.4: due_total = grand_total − paid_total + refunded_total.
                'due_total' => $order->grand_total->minus($derived->paid)->plus($derived->refunded),
            ];

            if ($derived->status === $order->payment_status) {
                $order->forceFill($totals)->save();

                return $order;
            }

            return $this->transitions->transitionPayment($order, $derived->status, attributes: $totals);
        });
    }
}
