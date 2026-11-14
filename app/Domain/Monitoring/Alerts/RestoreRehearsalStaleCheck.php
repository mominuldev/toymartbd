<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Console\Commands\Backup\RehearsalLog;
use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Models\SystemHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The last PASSED restore rehearsal is older than `days` (default 35 — it is monthly).
 *
 * Two sources, newest wins: this host's `system_heartbeats` row, and the rehearsal record on
 * the backup disk (App\Console\Commands\Backup\RehearsalLog), which also sees a rehearsal run
 * on staging against the same off-host disk. Only consulted when the heartbeat is stale, so
 * the disk is listed at most once per run.
 */
final readonly class RestoreRehearsalStaleCheck implements AlertCheck
{
    public function name(): string
    {
        return 'restore_rehearsal_stale';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Warning;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $days = max(1, $settings->int('alerts.restore_rehearsal_stale.days', 35));
        $cutoff = $now->subDays($days);
        $last = SystemHeartbeat::lastSuccess(SystemHeartbeat::RESTORE_REHEARSAL)?->toImmutable();

        if ($last === null || $last->lessThan($cutoff)) {
            $recorded = self::lastRecordedOnDisk();
            if ($recorded !== null && ($last === null || $recorded->greaterThan($last))) {
                $last = $recorded;
            }
        }

        if ($last === null) {
            return AlertResult::firing('monitoring.summary.restore_rehearsal_never', ['days' => $days]);
        }

        return $last->lessThan($cutoff)
            ? AlertResult::firing('monitoring.summary.restore_rehearsal_stale', ['age' => (int) $last->diffInDays($now, true), 'days' => $days])
            : AlertResult::ok();
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.restore_rehearsal_stale', ['age' => 36, 'days' => $settings->int('alerts.restore_rehearsal_stale.days', 35)]);
    }

    private static function lastRecordedOnDisk(): ?CarbonImmutable
    {
        if (! class_exists(RehearsalLog::class)) {
            return null;
        }

        try {
            $latest = (new RehearsalLog(Storage::disk((string) config('backup.disk'))))->latest();
        } catch (Throwable) {
            return null;
        }

        return $latest !== null && $latest['passed'] ? $latest['rehearsed_at'] : null;
    }
}
