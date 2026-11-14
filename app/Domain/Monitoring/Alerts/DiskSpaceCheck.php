<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use Carbon\CarbonImmutable;

/**
 * Low free space on the volume holding storage/ (logs, backup working files, uploads). Fires
 * below `min_free_percent` OR `min_free_gb`. Not applicable where PHP cannot measure the disk
 * (disk_free_space disabled). Covers this host only; the database host needs its own check.
 */
final class DiskSpaceCheck implements AlertCheck
{
    /** @var (callable(string): array{free: float|false, total: float|false})|null */
    public static $probe = null;

    public function name(): string
    {
        return 'disk_space';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Critical;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $path = storage_path();
        $measured = self::$probe !== null ? (self::$probe)($path) : [
            'free' => function_exists('disk_free_space') ? @disk_free_space($path) : false,
            'total' => function_exists('disk_total_space') ? @disk_total_space($path) : false,
        ];

        if ($measured['free'] === false || $measured['total'] === false || $measured['total'] <= 0) {
            return AlertResult::notApplicable('disk space not measurable');
        }

        $freeGb = $measured['free'] / 1024 ** 3;
        $percent = $measured['free'] * 100 / $measured['total'];
        $firing = $percent < $settings->int('alerts.disk_space.min_free_percent', 10)
            || $freeGb < $settings->int('alerts.disk_space.min_free_gb', 2);

        return $firing
            ? AlertResult::firing('monitoring.summary.disk_space', ['free_gb' => round($freeGb, 1), 'percent' => (int) floor($percent)])
            : AlertResult::ok();
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.disk_space', ['free_gb' => 1.5, 'percent' => 4]);
    }
}
