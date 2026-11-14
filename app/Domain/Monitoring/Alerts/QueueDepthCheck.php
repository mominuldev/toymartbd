<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Domain\Monitoring\Services\QueueMonitor;
use Carbon\CarbonImmutable;
use Throwable;

/** Any queue deeper than the threshold (§11: >500) — workers are down or far too slow. */
final readonly class QueueDepthCheck implements AlertCheck
{
    public function __construct(private QueueMonitor $queues) {}

    public function name(): string
    {
        return 'queue_depth';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Critical;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $threshold = max(1, $settings->int('alerts.queue_depth.threshold', 500));

        try {
            $depths = $this->queues->depths();
        } catch (Throwable) {
            return AlertResult::firing('monitoring.summary.queue_unreachable');
        }

        $deep = array_filter($depths, static fn (int $size): bool => $size > $threshold);
        if ($deep === []) {
            return AlertResult::ok();
        }

        $list = implode(', ', array_map(static fn (string $q, int $n): string => "{$q}={$n}", array_keys($deep), $deep));

        return AlertResult::firing('monitoring.summary.queue_depth', ['queues' => $list, 'threshold' => $threshold]);
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        $threshold = $settings->int('alerts.queue_depth.threshold', 500);

        return AlertResult::firing('monitoring.summary.queue_depth', ['queues' => 'notify='.($threshold + 1), 'threshold' => $threshold]);
    }
}
