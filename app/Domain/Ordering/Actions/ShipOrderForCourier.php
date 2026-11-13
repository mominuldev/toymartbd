<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Courier booking's order side (DATABASE-DESIGN §4.2 "on success", Phase 6). Runs nested in
 * Fulfilment's CompleteShipmentBooking transaction, reached through the ShipmentBooked
 * event (rule 2), so the booked shipment and the shipped order commit together. The
 * dispatch step itself is the one manual dispatch uses ({@see OrderDispatcher}).
 *
 * Idempotent per shipment: an order already shipped FOR THIS shipment is left alone (a
 * replayed completion); any other refusal throws, rolling the booking completion back.
 */
final readonly class ShipOrderForCourier
{
    public function __construct(private OrderDispatcher $dispatcher) {}

    /**
     * @throws InvalidOrderTransition
     * @throws InsufficientStock
     */
    public function handle(
        int $orderId,
        int $shipmentId,
        string $shipmentNumber,
        string $providerCode,
        ?string $trackingCode,
        ?int $userId = null,
    ): Order {
        return DB::transaction(function () use ($orderId, $shipmentId, $shipmentNumber, $providerCode, $trackingCode, $userId): Order {
            /** @var Order $locked */
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($orderId);

            if ($locked->status === OrderStatus::Shipped && $this->shippedFor($locked, $shipmentId)) {
                return $locked;
            }

            return $this->dispatcher->dispatch($locked, $userId, array_filter([
                'shipment_id' => $shipmentId,
                'shipment_number' => $shipmentNumber,
                'courier' => $providerCode,
                'tracking_reference' => $trackingCode,
            ], static fn (mixed $v): bool => $v !== null));
        });
    }

    private function shippedFor(Order $order, int $shipmentId): bool
    {
        return $order->events()
            ->where('field', 'status')
            ->where('to_status', OrderStatus::Shipped->value)
            ->where('meta->shipment_id', $shipmentId)
            ->exists();
    }
}
