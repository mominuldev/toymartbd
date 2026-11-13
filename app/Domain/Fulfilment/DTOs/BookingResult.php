<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Support\Money;

/**
 * A driver's answer to one `book()` call. `accepted = false` is a definite refusal by the
 * provider (validation, coverage, balance) with `error` saying why; a transport failure
 * (timeout, 5xx) is thrown, not returned, so the job can retry and the circuit breaker
 * can count it. `providerStatusRaw` is the provider's own status string, stored verbatim.
 */
final readonly class BookingResult
{
    /** @param  array<string, mixed>|null  $response  the provider's response body, credentials stripped */
    public function __construct(
        public bool $accepted,
        public ?string $consignmentId = null,
        public ?string $trackingCode = null,
        public ?Money $charge = null,
        public ?string $error = null,
        public ?string $providerStatusRaw = null,
        public ?string $trackingUrl = null,
        public ?array $response = null,
    ) {}

    public static function accepted(string $consignmentId, ?string $trackingCode = null, ?Money $charge = null, ?string $providerStatusRaw = null): self
    {
        return new self(true, $consignmentId, $trackingCode, $charge, null, $providerStatusRaw);
    }

    public static function refused(string $error, ?string $providerStatusRaw = null): self
    {
        return new self(false, error: $error, providerStatusRaw: $providerStatusRaw);
    }
}
