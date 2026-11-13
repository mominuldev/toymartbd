<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Any shipment status move, held until commit (notifications, admin refresh, tracking).
 * Payload is ids and status strings only.
 */
final readonly class ShipmentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $shipmentId,
        public int $orderId,
        public string $from,
        public string $to,
        public string $source,
    ) {}
}
