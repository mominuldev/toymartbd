<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Failed queue jobs in the window (§11: >20 an hour), read from Laravel's failed-jobs table. */
final readonly class FailedJobsCheck implements AlertCheck
{
    public function name(): string
    {
        return 'failed_jobs';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Warning;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $minutes = max(1, $settings->int('alerts.failed_jobs.window_minutes', 60));
        $threshold = max(1, $settings->int('alerts.failed_jobs.threshold', 20));
        $count = DB::connection(is_string($c = config('queue.failed.database')) ? $c : null)
            ->table((string) config('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', $now->subMinutes($minutes))
            ->count();

        return $count > $threshold
            ? AlertResult::firing('monitoring.summary.failed_jobs', ['count' => $count, 'minutes' => $minutes, 'threshold' => $threshold])
            : AlertResult::ok();
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.failed_jobs', ['count' => 21, 'minutes' => 60, 'threshold' => $settings->int('alerts.failed_jobs.threshold', 20)]);
    }
}
