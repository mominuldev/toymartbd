<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The courier reports the parcel delivered (§4.3). Dispatched inside ApplyCourierStatus's
 * transaction: Ordering's synchronous listener delivers the order through the existing
 * MarkOrderDelivered path (so revenue recognition stays in one place, on OrderDelivered);
 * Finance's queued listener writes `courier_cod_fee` after commit.
 */
final readonly class ShipmentDelivered
{
    use Dispatchable;

    public function __construct(
        public int $shipmentId,
        public int $orderId,
        public string $shipmentNumber,
        public string $providerCode,
        /** Decimal string; "0.00" for a prepaid parcel. */
        public string $codFee,
        public ?string $codCollected,
        /** ISO-8601 instant of delivery. */
        public string $deliveredAt,
    ) {}
}
