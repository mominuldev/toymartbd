<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use Carbon\CarbonImmutable;

/**
 * One provider's answer about one phone (rules 24, 33b). Either connected — counts and
 * ratios as the provider reported them, with when — or not connected with a machine
 * reason. A not-connected result carries NO numbers: the panel renders "provider not
 * connected", never a zero.
 *
 * Reasons: `no_merchant_account` (no credentials stored), `provider_unverified` (rule
 * 33a: the driver has not been exercised against the live API), `no_official_api` (the
 * provider publishes no history endpoint; we do not scrape — rule 24a),
 * `driver_missing`, `provider_error` (the call failed; retry later).
 */
final readonly class FraudLookupResult
{
    /** @param  array<string, mixed>|null  $raw */
    private function __construct(
        public bool $connected,
        public ?string $reason,
        public ?int $totalParcels = null,
        public ?int $totalDelivered = null,
        public ?int $totalCancelled = null,
        public ?int $totalFraudReports = null,
        public ?string $deliveryRatio = null,
        public ?string $cancellationRatio = null,
        public ?array $raw = null,
        public ?CarbonImmutable $fetchedAt = null,
    ) {}

    public static function notConnected(string $reason): self
    {
        return new self(false, $reason);
    }

    /** @param  array<string, mixed>|null  $raw */
    public static function connected(
        ?int $totalParcels,
        ?int $totalDelivered,
        ?int $totalCancelled,
        ?int $totalFraudReports,
        ?array $raw,
        CarbonImmutable $fetchedAt,
    ): self {
        $finished = ($totalDelivered ?? 0) + ($totalCancelled ?? 0);

        return new self(
            true,
            null,
            $totalParcels,
            $totalDelivered,
            $totalCancelled,
            $totalFraudReports,
            // Fractions of FINISHED parcels; null when nothing has finished — not 0.
            $finished > 0 && $totalDelivered !== null ? bcdiv((string) $totalDelivered, (string) $finished, 4) : null,
            $finished > 0 && $totalCancelled !== null ? bcdiv((string) $totalCancelled, (string) $finished, 4) : null,
            $raw,
            $fetchedAt,
        );
    }

    /** @param  array<string, mixed>|null  $raw */
    public static function fromStored(
        ?int $totalParcels,
        ?int $totalDelivered,
        ?int $totalCancelled,
        ?int $totalFraudReports,
        ?string $deliveryRatio,
        ?string $cancellationRatio,
        ?array $raw,
        CarbonImmutable $fetchedAt,
    ): self {
        return new self(true, null, $totalParcels, $totalDelivered, $totalCancelled, $totalFraudReports, $deliveryRatio, $cancellationRatio, $raw, $fetchedAt);
    }
}
