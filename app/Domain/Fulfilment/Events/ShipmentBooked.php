<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The courier accepted a parcel (DATABASE-DESIGN §4.2 "on success"). Dispatched INSIDE
 * CompleteShipmentBooking's transaction, on purpose: Ordering's synchronous listener marks
 * the order shipped (stock consumed) in that same transaction, so a booked shipment and a
 * shipped order commit together or not at all. Finance's listener is queued after commit
 * (`courier_charge`). Payload is scalars only — Finance and Ordering never read Fulfilment
 * models (deptrac).
 */
final readonly class ShipmentBooked
{
    use Dispatchable;

    public function __construct(
        public int $shipmentId,
        public int $orderId,
        public string $shipmentNumber,
        public string $providerCode,
        public ?string $consignmentId,
        public ?string $trackingCode,
        /** Decimal string, e.g. "70.00"; null when the provider reported no charge. */
        public ?string $charge,
        /** ISO-8601 instant the booking was confirmed. */
        public string $bookedAt,
        public ?int $userId = null,
    ) {}
}
