<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The courier reports the parcel returned to us — refused or undeliverable (§4.4 courier
 * return). Dispatched inside ApplyCourierStatus's transaction: Ordering's synchronous
 * listener restocks (`courier_return_in`) and moves the order to `returned`; Finance's
 * queued listener writes `courier_return_charge` and reverses revenue only if it had been
 * recognised. The outbound `courier_charge` stays as a sunk loss.
 */
final readonly class ShipmentReturned
{
    use Dispatchable;

    public function __construct(
        public int $shipmentId,
        public int $orderId,
        public string $shipmentNumber,
        public string $providerCode,
        /** Decimal string. */
        public string $returnCharge,
        /** ISO-8601 instant of the return. */
        public string $returnedAt,
    ) {}
}
