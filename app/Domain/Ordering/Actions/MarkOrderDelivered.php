<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderStateMachine;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Actions\CollectCodPayment;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Manual delivery confirmation (DATABASE-DESIGN §4.3; P5, until courier status updates
 * land in Phase 6). In ONE transaction:
 *
 *   lock the order → assert shipped → delivered (a second click is a 409) → every still
 *   pending COD payment → paid at its own amount (Payment's CollectCodPayment, the same
 *   PaymentTransitions path a courier collection takes) → order payment_status / paid
 *   totals re-derived (SyncOrderPaymentStatus) → status delivered + delivered_at, one
 *   customer-visible order_events row → OrderDelivered after commit.
 *
 * Ledger rows are NOT written here: Finance's RecognizeRevenueOnDelivery listens to
 * OrderDelivered and writes sale_revenue / discount_given / shipping_revenue / cogs,
 * all recognised and none cash. The COD money is still with the courier — cash is
 * `cod_received` at remittance (§4.5, rule 10). Customer aggregates refresh from the
 * OrderStatusTransition listener. fulfillment_status stays `fulfilled` (the enum has no
 * delivered value; delivery is the order status).
 */
final readonly class MarkOrderDelivered
{
    public function __construct(
        private OrderTransitions $transitions,
        private CollectCodPayment $collectCod,
        private SyncOrderPaymentStatus $syncPayment,
    ) {}

    /** @throws InvalidOrderTransition when the order is not shipped (409) */
    public function handle(Order $order, ?int $userId = null, ?string $note = null): Order
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        return DB::transaction(function () use ($order, $userId, $note): Order {
            /** @var Order $locked */
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            OrderStateMachine::assertStatus($locked->status, OrderStatus::Delivered);

            $collected = [];
            $pendingCod = Payment::query()->where('order_id', $locked->id)
                ->where('method', PaymentMethod::Cod->value)
                ->where('status', PaymentStatus::Pending->value)
                ->orderBy('id')->get();
            foreach ($pendingCod as $payment) {
                $paid = $this->collectCod->handle($payment, $payment->amount, $userId);
                $collected[] = ['payment_id' => $paid->id, 'amount' => $paid->amount->toDecimal()];
            }

            $delivered = $this->transitions->transition(
                $locked,
                OrderStatus::Delivered,
                $userId,
                meta: array_filter(['manual' => true, 'cod_collected' => $collected], static fn (mixed $v): bool => $v !== []),
                description: $note,
            );

            if ($collected !== []) {
                return $this->syncPayment->handle($delivered->id);
            }

            return $delivered;
        });
    }
}
