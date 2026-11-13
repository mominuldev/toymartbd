<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Listeners\Fulfilment;

use App\Domain\Fulfilment\Events\ShipmentBooked;
use App\Domain\Ordering\Actions\ShipOrderForCourier;

/**
 * ShipmentBooked → the order is marked shipped (stock consumed). SYNCHRONOUS on purpose:
 * it runs inside Fulfilment's booking-completion transaction (§4.2: one transaction), and
 * a refusal (order cancelled meanwhile, stock miscount) throws and rolls the completion
 * back — Fulfilment then keeps the consignment and puts the shipment in the retry queue.
 */
final readonly class ShipOrderOnShipmentBooked
{
    public function __construct(private ShipOrderForCourier $ship) {}

    public function handle(ShipmentBooked $event): void
    {
        $this->ship->handle($event->orderId, $event->shipmentId, $event->shipmentNumber, $event->providerCode, $event->trackingCode, $event->userId);
    }
}
