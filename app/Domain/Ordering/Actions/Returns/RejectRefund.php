<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions\Returns;

use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Exceptions\ReturnNotAllowed;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Models\RefundItem;
use Illuminate\Support\Facades\DB;

/**
 * Refuse a refund before approval (requested → rejected). Only a `requested` refund can be
 * rejected: an approved one is already booked in the ledger, so withdrawing it would need
 * a reversing entry — not offered at MVP. Units it covered stop counting as refunded; the
 * goods stay returned (stock and COGS already moved at inspection); its return closes.
 */
final readonly class RejectRefund
{
    public function handle(Refund $refund, string $note, ?int $userId = null): Refund
    {
        return DB::transaction(static function () use ($refund, $note, $userId): Refund {
            /** @var Order $order */
            $order = Order::query()->lockForUpdate()->findOrFail($refund->order_id);
            /** @var Refund $locked */
            $locked = Refund::query()->with('items')->lockForUpdate()->findOrFail($refund->id);

            if ($locked->status !== RefundStatus::Requested) {
                throw ReturnNotAllowed::refundStatus($locked->id, $locked->status->value, RefundStatus::Rejected->value);
            }

            $locked->status = RefundStatus::Rejected;
            $locked->note = trim($note);
            $locked->save();

            $locked->items->each(static fn (RefundItem $i) => OrderItem::query()->whereKey($i->order_item_id)
                ->where('quantity_refunded', '>=', $i->quantity)
                ->decrement('quantity_refunded', $i->quantity));

            OrderReturn::query()->where('refund_id', $locked->id)->where('status', ReturnStatus::Inspected->value)
                ->update(['status' => ReturnStatus::Closed->value, 'closed_at' => now()]);

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'type' => OrderEventType::RefundRejected,
                'description' => trim($note),
                'meta' => ['refund_id' => $locked->id],
                'user_id' => $userId,
                'is_customer_visible' => false,
            ]);

            return $locked;
        });
    }
}
