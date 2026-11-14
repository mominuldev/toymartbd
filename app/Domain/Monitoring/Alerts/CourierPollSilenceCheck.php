<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Models\SystemHeartbeat;
use Carbon\CarbonImmutable;

/**
 * A courier holds shipments that have not changed for `minutes`, and its API has not answered
 * us successfully (poll or booking) for as long: the 15-minute reconciliation poll is not
 * running, the circuit is stuck open, or the credentials stopped working.
 */
final readonly class CourierPollSilenceCheck implements AlertCheck
{
    public function name(): string
    {
        return 'courier_poll_silence';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Warning;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $minutes = max(1, $settings->int('alerts.courier_poll_silence.minutes', 90));
        $cutoff = $now->subMinutes($minutes);
        $silent = [];
        foreach (InFlightShipments::providersWithShipmentsOlderThan($cutoff) as $code) {
            $last = SystemHeartbeat::lastSuccess(SystemHeartbeat::COURIER_PREFIX.$code);
            if ($last === null || $last->lessThan($cutoff)) {
                $silent[] = $code;
            }
        }

        return $silent === []
            ? AlertResult::ok()
            : AlertResult::firing('monitoring.summary.courier_poll_silence', ['providers' => implode(', ', $silent), 'minutes' => $minutes]);
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.courier_poll_silence', ['providers' => 'test-courier', 'minutes' => $settings->int('alerts.courier_poll_silence.minutes', 90)]);
    }
}
