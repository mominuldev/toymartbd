<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Services\OrderTransitions;
use Illuminate\Support\Facades\DB;

/**
 * A payment of this order was flagged for review (amount/currency mismatch, paid after the
 * attempt closed — rule 19). The order is put on hold for a human, never cancelled and
 * never marked paid. An order that can no longer be held (already cancelled, say) gets a
 * staff-only timeline row instead, so the money is not silently forgotten.
 */
final readonly class HoldOrderForPaymentReview
{
    public function __construct(private OrderTransitions $transitions) {}

    public function handle(int $orderId, int $paymentId, string $reason): void
    {
        DB::transaction(function () use ($orderId, $paymentId, $reason): void {
            /** @var Order|null $order */
            $order = Order::query()->lockForUpdate()->find($orderId);
            if ($order === null) {
                return;
            }
            $meta = ['payment_review' => $reason, 'payment_id' => $paymentId];
            $description = "Payment {$paymentId} needs review: {$reason}";

            if ($order->status !== OrderStatus::OnHold && $order->status->canTransitionTo(OrderStatus::OnHold)) {
                $this->transitions->transition($order, OrderStatus::OnHold, meta: $meta, description: $description);

                return;
            }

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'type' => OrderEventType::Note,
                'meta' => $meta,
                'description' => $description,
                'is_customer_visible' => false,
            ]);
        });
    }
}
