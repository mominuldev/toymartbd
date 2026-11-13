<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Listeners\Fulfilment;

use App\Domain\Fulfilment\Events\ShipmentReturned;
use App\Domain\Ordering\Actions\RecordCourierReturn;

/** ShipmentReturned → courier return on the order (§4.4). Synchronous, inside ApplyCourierStatus's transaction. */
final readonly class ReturnOrderOnShipmentReturned
{
    public function __construct(private RecordCourierReturn $return) {}

    public function handle(ShipmentReturned $event): void
    {
        $this->return->handle($event->orderId, $event->shipmentId, $event->shipmentNumber);
    }
}
