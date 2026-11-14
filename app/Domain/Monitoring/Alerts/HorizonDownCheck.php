<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Domain\Monitoring\Services\QueueMonitor;
use Carbon\CarbonImmutable;

/** No Horizon master supervisor is alive: no queued work runs — order confirmations, couriers, SMS. */
final readonly class HorizonDownCheck implements AlertCheck
{
    public function __construct(private QueueMonitor $queues) {}

    public function name(): string
    {
        return 'horizon_down';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Critical;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $running = $this->queues->horizonRunning();

        return match ($running) {
            null => AlertResult::notApplicable('queue connection is not redis'),
            true => AlertResult::ok(),
            false => AlertResult::firing('monitoring.summary.horizon_down'),
        };
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.horizon_down');
    }
}
