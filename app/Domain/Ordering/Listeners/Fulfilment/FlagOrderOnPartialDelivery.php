<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Listeners\Fulfilment;

use App\Domain\Fulfilment\Events\ShipmentPartiallyDelivered;
use App\Domain\Ordering\Actions\AddOrderNote;
use App\Domain\Ordering\Models\Order;

/**
 * ShipmentPartiallyDelivered → an internal note on the order for staff. Providers report
 * the amount collected, not which lines were delivered, so settling the delivered lines
 * (COD, `partially_returned`, restocking the rest) is a staff step — never guessed.
 */
final readonly class FlagOrderOnPartialDelivery
{
    public function __construct(private AddOrderNote $note) {}

    public function handle(ShipmentPartiallyDelivered $event): void
    {
        $order = Order::query()->find($event->orderId);
        if ($order === null) {
            return;
        }

        $collected = $event->codCollected ?? 'unknown';
        $this->note->handle($order, "Courier ({$event->providerCode}) reports PARTIAL delivery of shipment {$event->shipmentNumber}; COD collected: {$collected}. Settle the delivered lines and the courier return by hand.");
    }
}
