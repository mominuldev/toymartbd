<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * One recipient's copy of a scheduled report run (P9):
 *   queued   waiting for the send job (first send, or a resend asked for by staff)
 *   sent     mailed — never mailed again for this period
 *   skipped  not allowed to see the report at send time (inactive, or lost the permission)
 *   failed   the mail (or building their copy) failed; staff may resend it
 */
enum ReportDeliveryStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
