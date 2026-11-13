<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Enums;

enum DispatchStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case SkippedConsent = 'skipped_consent';
    case SkippedDisabled = 'skipped_disabled';
    case SkippedStale = 'skipped_stale';
    case NotApplicable = 'not_applicable';
}
