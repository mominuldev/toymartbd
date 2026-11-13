<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Domain\Fulfilment\Models\Shipment;

/** One order's result in a bulk shipment request: `requested`, `conflict` or `not_found`. */
final readonly class ShipmentRequestOutcome
{
    public const string REQUESTED = 'requested';

    public const string CONFLICT = 'conflict';

    public const string NOT_FOUND = 'not_found';

    public function __construct(
        public int $orderId,
        public string $result,
        public ?Shipment $shipment = null,
        public ?string $message = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->result === self::REQUESTED;
    }
}
