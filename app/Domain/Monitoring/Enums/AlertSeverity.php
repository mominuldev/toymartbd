<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Enums;

/** How loudly an alert is delivered: every severity is mailed; SMS from `monitoring.alerts.channels.sms_min_severity`. */
enum AlertSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function atLeast(self $other): bool
    {
        return $this === self::Critical || $other === self::Warning;
    }
}
