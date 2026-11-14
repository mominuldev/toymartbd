<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Models\SystemHeartbeat;
use Carbon\CarbonImmutable;

/** The last successful `toymart:backup` is older than `hours` (default 26), or there never was one. */
final readonly class BackupStaleCheck implements AlertCheck
{
    public function name(): string
    {
        return 'backup_stale';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Critical;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $hours = max(1, $settings->int('alerts.backup_stale.hours', 26));
        $last = SystemHeartbeat::lastSuccess(SystemHeartbeat::BACKUP);

        if ($last === null) {
            return AlertResult::firing('monitoring.summary.backup_never', ['hours' => $hours]);
        }

        $age = (int) $last->diffInHours($now, true);

        return $age >= $hours
            ? AlertResult::firing('monitoring.summary.backup_stale', ['age' => $age, 'hours' => $hours])
            : AlertResult::ok();
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.backup_stale', ['age' => 27, 'hours' => $settings->int('alerts.backup_stale.hours', 26)]);
    }
}
