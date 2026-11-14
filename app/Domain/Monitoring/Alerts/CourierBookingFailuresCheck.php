<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use Carbon\CarbonImmutable;

/**
 * Courier booking failures in the window (§11: failure rate >10% in 15 min). Counts shipments
 * whose last booking attempt fell in the window: failed ones now in `failed`, the rest
 * succeeded. Fires when failures reach `min_failures` AND the rate exceeds `rate_percent`, so
 * one refused parcel on a quiet afternoon does not page the owner.
 */
final readonly class CourierBookingFailuresCheck implements AlertCheck
{
    public function name(): string
    {
        return 'courier_booking_failures';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Warning;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $minutes = max(1, $settings->int('alerts.courier_booking_failures.window_minutes', 15));
        $since = $now->subMinutes($minutes);
        $attempts = Shipment::query()->where('last_attempt_at', '>=', $since)->count();
        $failures = Shipment::query()->where('last_attempt_at', '>=', $since)->where('status', ShipmentStatus::Failed->value)->count();
        $rate = $attempts === 0 ? 0 : (int) round($failures * 100 / $attempts);

        $firing = $failures >= max(1, $settings->int('alerts.courier_booking_failures.min_failures', 3))
            && $rate > $settings->int('alerts.courier_booking_failures.rate_percent', 10);

        return $firing
            ? AlertResult::firing('monitoring.summary.courier_booking_failures', ['failures' => $failures, 'attempts' => $attempts, 'rate' => $rate, 'minutes' => $minutes])
            : AlertResult::ok();
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.courier_booking_failures', ['failures' => 3, 'attempts' => 10, 'rate' => 30, 'minutes' => $settings->int('alerts.courier_booking_failures.window_minutes', 15)]);
    }
}
