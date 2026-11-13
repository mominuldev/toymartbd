<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * A scheduled report's run for one period:
 *   queued   claimed by the sweep (or resent by staff, P9), job on the `reports` queue
 *   sending  the job has started (a duplicate job sees this and does nothing)
 *   sent     every eligible recipient was mailed
 *   skipped  no recipient was eligible (inactive, or lost the permission) — nothing sent
 *   failed   at least one recipient's copy failed; never retried automatically. Staff may
 *            resend it (ResendReportRun, P9): only the failed copies go out again, so
 *            nobody who already got the email gets it twice
 */
enum ReportRunStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
