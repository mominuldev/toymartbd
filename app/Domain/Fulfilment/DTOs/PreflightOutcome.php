<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

/**
 * Whether a shipment may go to its courier now: either the request to send, or a
 * machine-readable failure code (`Shipment::FAILURE_*`) plus a message for staff.
 */
final readonly class PreflightOutcome
{
    private function __construct(
        public ?ShipmentRequest $request,
        public ?string $failureCode,
        public ?string $message,
    ) {}

    public static function ready(ShipmentRequest $request): self
    {
        return new self($request, null, null);
    }

    public static function blocked(string $code, string $message): self
    {
        return new self(null, $code, $message);
    }

    public function isReady(): bool
    {
        return $this->request !== null;
    }
}
