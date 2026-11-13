<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Steadfast;

use RuntimeException;

/**
 * A transport-level failure talking to Steadfast — timeout, connection error, 5xx, 429,
 * or a response that is not the documented JSON. THROWN, never returned, so the booking
 * job retries it and the circuit breaker counts it (CourierGateway contract). Retrying a
 * booking is safe: Steadfast enforces a unique `invoice`, so a repeat is refused rather
 * than double-booked (research §6.10).
 *
 * The message never contains credentials, a full phone number or an address (rule 20).
 */
final class SteadfastUnavailable extends RuntimeException
{
    public static function connection(string $operation): self
    {
        return new self("steadfast {$operation}: could not reach the provider (timeout or connection error).");
    }

    public static function http(string $operation, int $status): self
    {
        return new self("steadfast {$operation}: provider answered HTTP {$status}.");
    }

    public static function malformed(string $operation, int $status): self
    {
        return new self("steadfast {$operation}: unexpected response body (HTTP {$status}).");
    }
}
