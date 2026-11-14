<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Monitoring\Contracts\AlertCheck;
use App\Domain\Monitoring\DTOs\AlertResult;
use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Services\MonitoringSettings;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationStatus;
use App\Domain\Notification\Models\NotificationLog;
use Carbon\CarbonImmutable;

/**
 * A spike in SMS send failures (OTP and order updates ride on SMS): failed or dead-lettered
 * sends in the window reach `min_failures` and exceed `rate_percent` of the window's sends.
 * Usually an exhausted SMS balance or a provider outage — customers cannot log in.
 */
final readonly class SmsFailuresCheck implements AlertCheck
{
    public function name(): string
    {
        return 'sms_failures';
    }

    public function severity(): AlertSeverity
    {
        return AlertSeverity::Warning;
    }

    public function evaluate(MonitoringSettings $settings, CarbonImmutable $now): AlertResult
    {
        $minutes = max(1, $settings->int('alerts.sms_failures.window_minutes', 30));
        $since = $now->subMinutes($minutes);
        $failures = NotificationLog::query()
            ->where('channel', NotificationChannel::Sms->value)
            ->whereIn('status', [NotificationStatus::Failed->value, NotificationStatus::DeadLettered->value])
            ->where('failed_at', '>=', $since)
            ->count();
        $sent = NotificationLog::query()
            ->where('channel', NotificationChannel::Sms->value)
            ->where('status', NotificationStatus::Sent->value)
            ->where('sent_at', '>=', $since)
            ->count();
        $total = $failures + $sent;
        $rate = $total === 0 ? 0 : (int) round($failures * 100 / $total);

        $firing = $failures >= max(1, $settings->int('alerts.sms_failures.min_failures', 5))
            && $rate > $settings->int('alerts.sms_failures.rate_percent', 20);

        return $firing
            ? AlertResult::firing('monitoring.summary.sms_failures', ['failures' => $failures, 'total' => $total, 'rate' => $rate, 'minutes' => $minutes])
            : AlertResult::ok();
    }

    public function sample(MonitoringSettings $settings): AlertResult
    {
        return AlertResult::firing('monitoring.summary.sms_failures', ['failures' => 5, 'total' => 8, 'rate' => 63, 'minutes' => $settings->int('alerts.sms_failures.window_minutes', 30)]);
    }
}
