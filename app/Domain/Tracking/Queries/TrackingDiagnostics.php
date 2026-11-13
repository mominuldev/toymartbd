<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Queries;

use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingSource;
use App\Domain\Tracking\Models\TrackingEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Read-only health numbers for Admin → Integrations → Tracking → Diagnostics (PRD K-03):
 * per destination, rolling 24 h / 7 d counts by dispatch status, the consent-skip rate, the
 * browser↔server pairing rate (the dedup health number) and the last success / last error.
 *
 * Windows are rolling (now − 24 h, now − 7 d) on `created_at`, not Dhaka calendar days —
 * this is a live health check, not a daily report (rule 11 governs the event-log date
 * filter, which does use Dhaka dates).
 *
 * Pairing rate (Meta only): of the `browser` rows Meta was eligible for (not
 * not_applicable / skipped_disabled), the share whose server twin was `sent` with the same
 * event_id — the precondition for Meta to deduplicate the pixel hit against the CAPI event.
 * GA4 has no dedup, so each event goes through ONE channel there (DispatchPolicy) and the
 * pairing rate is null by design.
 *
 * @phpstan-type Counts array{sent: int, failed: int, pending: int, skipped_consent: int, skipped_other: int, total: int}
 * @phpstan-type DestinationHealth array{destination: string, last_success_at: ?CarbonImmutable, last_error: ?string, last_error_at: ?CarbonImmutable, window_24h: Counts, window_7d: Counts, consent_skip_rate: ?string, pairing_rate: ?string}
 */
final readonly class TrackingDiagnostics
{
    /** @return array{generated_at: CarbonImmutable, destinations: list<DestinationHealth>} */
    public function summary(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $destinations = [];

        foreach (TrackingEvent::DESTINATIONS as $destination) {
            $day = $this->counts($destination, $now->subDay());
            $week = $this->counts($destination, $now->subDays(7));
            $consentBase = $week['total'];

            $lastError = TrackingEvent::query()
                ->where($destination.'_status', DispatchStatus::Failed->value)
                ->orderByDesc('id')
                ->first();

            $lastSuccess = TrackingEvent::query()
                ->where($destination.'_status', DispatchStatus::Sent->value)
                ->max('dispatched_at');

            $destinations[] = [
                'destination' => $destination,
                'last_success_at' => is_string($lastSuccess) || $lastSuccess instanceof DateTimeInterface ? CarbonImmutable::parse($lastSuccess, 'UTC') : null,
                'last_error' => $lastError !== null ? self::errorText($lastError->getAttribute($destination.'_response')) : null,
                'last_error_at' => $lastError?->dispatched_at !== null ? CarbonImmutable::instance($lastError->dispatched_at) : ($lastError?->created_at !== null ? CarbonImmutable::instance($lastError->created_at) : null),
                'window_24h' => $day,
                'window_7d' => $week,
                'consent_skip_rate' => $consentBase > 0 ? self::percent($week['skipped_consent'], $consentBase) : null,
                'pairing_rate' => $destination === 'meta' ? $this->pairingRate($now->subDays(7)) : null,
            ];
        }

        return ['generated_at' => $now, 'destinations' => $destinations];
    }

    /**
     * Status counts for one destination since `$since`. `not_applicable` rows (an event
     * this destination never takes) are left out of every number, including `total`.
     *
     * @return Counts
     */
    private function counts(string $destination, CarbonImmutable $since): array
    {
        $column = $destination.'_status';
        /** @var array<string, int> $rows */
        $rows = TrackingEvent::query()
            ->where('created_at', '>=', $since)
            ->where($column, '!=', DispatchStatus::NotApplicable->value)
            ->groupBy($column)
            ->pluck(DB::raw('count(*) as n'), $column)
            ->map(static fn (mixed $n): int => is_numeric($n) ? (int) $n : 0)
            ->all();

        $sent = $rows[DispatchStatus::Sent->value] ?? 0;
        $failed = $rows[DispatchStatus::Failed->value] ?? 0;
        $pending = $rows[DispatchStatus::Pending->value] ?? 0;
        $consent = $rows[DispatchStatus::SkippedConsent->value] ?? 0;
        $other = ($rows[DispatchStatus::SkippedDisabled->value] ?? 0) + ($rows[DispatchStatus::SkippedStale->value] ?? 0);

        return [
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
            'skipped_consent' => $consent,
            'skipped_other' => $other,
            'total' => array_sum($rows),
        ];
    }

    private function pairingRate(CarbonImmutable $since): ?string
    {
        $eligible = TrackingEvent::query()
            ->where('created_at', '>=', $since)
            ->where('source', TrackingSource::Browser->value)
            ->whereNotIn('meta_status', [DispatchStatus::NotApplicable->value, DispatchStatus::SkippedDisabled->value]);

        $total = (clone $eligible)->count();
        if ($total === 0) {
            return null;
        }

        return self::percent((clone $eligible)->where('meta_status', DispatchStatus::Sent->value)->count(), $total);
    }

    /** One decimal place, as a string ("87.5") — never a float in the API. */
    private static function percent(int $part, int $whole): string
    {
        return number_format(intdiv($part * 1000 + intdiv($whole, 2), $whole) / 10, 1, '.', '');
    }

    /** The stored provider response's error text, already scrubbed of secrets at write time. */
    public static function errorText(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }
        foreach (['error', 'response_excerpt', 'excerpt'] as $key) {
            if (isset($response[$key]) && is_string($response[$key]) && $response[$key] !== '') {
                return mb_substr($response[$key], 0, 300);
            }
        }

        return null;
    }
}
