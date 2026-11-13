<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Listeners\Fulfilment;

use App\Domain\Fulfilment\Events\ShipmentDelivered;
use App\Domain\Ordering\Actions\MarkOrderDelivered;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * ShipmentDelivered → the order is delivered through the SAME path as manual delivery
 * (MarkOrderDelivered: COD collected, OrderDelivered → Finance recognises revenue), so
 * revenue recognition stays in one place. Synchronous, inside ApplyCourierStatus's
 * transaction. An order already delivered (staff marked it by hand first) is a no-op; an
 * order that was never marked shipped is logged for staff rather than forced.
 */
final readonly class DeliverOrderOnShipmentDelivered
{
    public function __construct(private MarkOrderDelivered $deliver) {}

    public function handle(ShipmentDelivered $event): void
    {
        $order = Order::query()->find($event->orderId);
        if ($order === null || in_array($order->status, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
            return;
        }
        if ($order->status !== OrderStatus::Shipped) {
            Log::warning('courier.delivered.order_not_shipped', ['order_id' => $order->id, 'shipment_id' => $event->shipmentId, 'status' => $order->status->value]);

            return;
        }

        $this->deliver->handle($order, null, "Delivered by courier ({$event->providerCode}, {$event->shipmentNumber})");
    }
}
