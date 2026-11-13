<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Tracking\Queries\TrackingDiagnostics;
use App\Http\Resources\ApiResource;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Tracking health per destination (PRD K-03), computed on the server (rule 1): rolling 24 h
 * and 7 d counts by dispatch status, the consent-skip rate and the browser↔server pairing
 * rate (percent strings, one decimal, null when there is nothing to measure) and the last
 * success / last error. See {@see TrackingDiagnostics}.
 *
 * @phpstan-import-type DestinationHealth from TrackingDiagnostics
 *
 * @property array{generated_at: CarbonImmutable, destinations: list<DestinationHealth>} $resource
 */
#[SchemaName('TrackingSummary')]
final class TrackingSummaryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $destinations = [];
        foreach ($this->resource['destinations'] as $d) {
            $destinations[] = [
                /** @var 'ga4'|'meta' */
                'destination' => $d['destination'],
                /** @var string|null */
                'last_success_at' => TrackingEventResource::utc($d['last_success_at']),
                /** @var string|null Provider error text, scrubbed of secrets. */
                'last_error' => $d['last_error'],
                /** @var string|null */
                'last_error_at' => TrackingEventResource::utc($d['last_error_at']),
                /** @var array{sent: int, failed: int, pending: int, skipped_consent: int, skipped_other: int, total: int} */
                'window_24h' => $d['window_24h'],
                /** @var array{sent: int, failed: int, pending: int, skipped_consent: int, skipped_other: int, total: int} */
                'window_7d' => $d['window_7d'],
                /** @var string|null Percent, one decimal ("12.5"), over 7 days. */
                'consent_skip_rate' => $d['consent_skip_rate'],
                /** @var string|null Percent, one decimal, over 7 days. Meta only; null for GA4 (one channel per event, no dedup). */
                'pairing_rate' => $d['pairing_rate'],
            ];
        }

        return [
            /** @var string */
            'generated_at' => TrackingEventResource::utc($this->resource['generated_at']),
            /** @var list<array{destination: 'ga4'|'meta', last_success_at: string|null, last_error: string|null, last_error_at: string|null, window_24h: array{sent: int, failed: int, pending: int, skipped_consent: int, skipped_other: int, total: int}, window_7d: array{sent: int, failed: int, pending: int, skipped_consent: int, skipped_other: int, total: int}, consent_skip_rate: string|null, pairing_rate: string|null}> */
            'destinations' => $destinations,
        ];
    }
}
