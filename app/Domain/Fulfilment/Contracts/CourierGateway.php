<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\BulkBookingResult;
use App\Domain\Fulfilment\DTOs\CourierQuote;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\DTOs\CourierWebhookEvent;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;

/**
 * One courier provider (rule 5: a courier is added by writing a driver, never by touching
 * order code). Drivers are resolved by {@see \App\Domain\Fulfilment\Services\CourierRegistry}
 * and are only ever called from queued jobs, outside any database transaction (rule 4).
 *
 * A transport failure (timeout, connection error, 5xx) is THROWN so the caller can retry
 * and count it towards the circuit breaker; a provider's definite refusal is RETURNED as
 * `BookingResult::refused()`.
 */
interface CourierGateway
{
    /** The `courier_providers.code` this driver serves. */
    public function code(): string;

    /** Whether booking needs a mapped `courier_locations` row (Pathao yes, Steadfast no). */
    public function requiresLocationMapping(): bool;

    public function supportsBulk(): bool;

    public function quote(ShipmentRequest $r): CourierQuote;

    public function book(ShipmentRequest $r): BookingResult;

    /**
     * Neither provider returns consignment ids from bulk (rule 24d).
     *
     * @param  list<ShipmentRequest>  $rs
     */
    public function bookMany(array $rs): BulkBookingResult;

    public function status(string $consignmentId): CourierStatusResult;

    /** @param  array<string, list<string>|string>  $headers */
    public function verifyWebhook(array $headers, string $rawBody): bool;

    public function parseWebhook(string $rawBody): ?CourierWebhookEvent;
}
