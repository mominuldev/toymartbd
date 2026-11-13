<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The courier delivered some items of the parcel (§3.6 "partial delivery is a real
 * state"). Providers report the collected amount, not which lines, so the order is
 * flagged for staff rather than settled automatically. Dispatched inside
 * ApplyCourierStatus's transaction.
 */
final readonly class ShipmentPartiallyDelivered
{
    use Dispatchable;

    public function __construct(
        public int $shipmentId,
        public int $orderId,
        public string $shipmentNumber,
        public string $providerCode,
        public ?string $codCollected,
        public string $occurredAt,
    ) {}
}
