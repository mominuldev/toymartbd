<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Inventory\Actions\ReleaseStock;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Services\PaymentTransitions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cancel before shipping (P4-ORD-09, DATABASE-DESIGN §4.4), in one transaction:
 *
 *   lock the order → assert it is still pre-shipment → release its stock reservation
 *   (Inventory's ReleaseStock: levels locked FOR UPDATE in ascending variant id) →
 *   cancel its open payments → status = cancelled with reason, one order_events row.
 *
 * Coupon-usage reversal and the risk profile's cancelled_count are owned by Marketing and
 * Risk; they react to {@see \App\Domain\Ordering\Events\OrderCancelled}, dispatched after
 * commit (cross-domain writes go through events, rule 2). Money already taken online is
 * not refunded here — `OrderCancelled::$paidTotal` > 0 flags it for the refund flow.
 */
final readonly class CancelOrder
{
    public function __construct(
        private OrderTransitions $transitions,
        private ReleaseStock $releaseStock,
        private PaymentTransitions $payments,
    ) {}

    /** @throws OrderNotCancellable */
    public function handle(Order $order, CancellationReason $reason, ?string $note = null, ?int $userId = null): Order
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($reason->requiresNote() && $note === null) {
            throw new InvalidArgumentException('A cancellation for "other" needs a note.');
        }

        return DB::transaction(function () use ($order, $reason, $note, $userId): Order {
            /** @var Order $locked */
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);

            if (! $locked->status->isCancellable()) {
                throw OrderNotCancellable::inStatus($locked->number, $locked->status->value);
            }

            $lines = $locked->reservedLines();
            if ($lines !== []) {
                $this->releaseStock->handle($lines);
            }

            $this->payments->cancelOpen($locked->id, 'order_cancelled');

            return $this->transitions->transition(
                $locked,
                OrderStatus::Cancelled,
                $userId,
                attributes: ['cancel_reason' => $reason, 'cancel_note' => $note],
                meta: ['reason' => $reason->value, 'released' => $lines],
                description: $note,
            );
        });
    }
}
