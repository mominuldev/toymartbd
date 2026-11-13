<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Inventory\Actions\RestockCourierReturn;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderDispatcher;
use App\Domain\Ordering\Services\OrderTransitions;
use App\Domain\Payment\Services\PaymentTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Courier return — shipped, then refused or undeliverable (DATABASE-DESIGN §4.4). Reached
 * through Fulfilment's ShipmentReturned event, nested in ApplyCourierStatus's transaction:
 *
 *   lock the order → every unit still out (quantity_shipped − quantity_returned) comes back
 *   as `courier_return_in` at its cost snapshot (Inventory's RestockCourierReturn) →
 *   quantity_returned → open COD payments cancelled (nothing was collected) and the order's
 *   payment status re-derived → fulfillment_status returned → status returned + returned_at.
 *
 * The ledger side (courier_return_charge, and reversing revenue only if it had been
 * recognised) is Finance's queued listener on the same event. Risk/customer counters
 * refresh from the OrderStatusTransition listeners. An order already returned is a no-op
 * (a replayed event); an order in a state with no courier return (never shipped) is logged
 * and left for staff, never forced.
 */
final readonly class RecordCourierReturn
{
    public function __construct(
        private OrderTransitions $transitions,
        private RestockCourierReturn $restock,
        private PaymentTransitions $payments,
        private SyncOrderPaymentStatus $syncPayment,
    ) {}

    public function handle(int $orderId, int $shipmentId, string $shipmentNumber, ?int $userId = null): ?Order
    {
        return DB::transaction(function () use ($orderId, $shipmentId, $shipmentNumber, $userId): ?Order {
            /** @var Order $locked */
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($orderId);

            if ($locked->status === OrderStatus::Returned) {
                return $locked;
            }
            if (! $locked->status->canTransitionTo(OrderStatus::Returned) || $locked->status === OrderStatus::PartiallyReturned) {
                Log::warning('courier.return.order_not_returnable', ['order_id' => $locked->id, 'shipment_id' => $shipmentId, 'status' => $locked->status->value]);

                return null;
            }

            $lines = [];
            $returned = [];
            foreach ($locked->items as $item) {
                $out = $item->quantity_shipped - $item->quantity_returned;
                if ($out <= 0) {
                    continue;
                }
                if ($item->variant_id !== null) {
                    $lines[] = ['variant_id' => $item->variant_id, 'quantity' => $out, 'unit_cost' => $item->cost_price_snapshot];
                    $returned[] = ['variant_id' => $item->variant_id, 'quantity' => $out];
                }
                $item->quantity_returned += $out;
                $item->save();
            }
            $this->restock->handle($lines, OrderDispatcher::STOCK_REFERENCE, $locked->id, $userId);

            if ($locked->status === OrderStatus::Shipped && $this->payments->cancelOpen($locked->id, 'courier_return') !== []) {
                $locked = $this->syncPayment->handle($locked->id);
            }

            if ($locked->fulfillment_status->canTransitionTo(FulfillmentStatus::Returned)) {
                $this->transitions->transitionFulfillment($locked, FulfillmentStatus::Returned, $userId);
            }

            return $this->transitions->transition($locked, OrderStatus::Returned, $userId, meta: [
                'courier_return' => true,
                'shipment_id' => $shipmentId,
                'shipment_number' => $shipmentNumber,
                'restocked' => $returned,
            ]);
        });
    }
}
