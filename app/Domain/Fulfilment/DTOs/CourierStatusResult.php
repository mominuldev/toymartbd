<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The authoritative status of one consignment, read from the provider (a webhook is only
 * a trigger to fetch this, rules 19 and 24c). `status` is the driver's normalisation of
 * `rawStatus` through its `statusMap()`. `consignmentId` is set when the result comes
 * from a lookup that also resolves a submitted (bulk) shipment's id.
 */
final readonly class CourierStatusResult
{
    public function __construct(
        public ShipmentStatus $status,
        public string $rawStatus,
        public ?CarbonImmutable $occurredAt = null,
        public ?Money $codCollected = null,
        public ?Money $charge = null,
        public ?string $consignmentId = null,
        public ?string $trackingCode = null,
        public ?string $message = null,
    ) {}
}
