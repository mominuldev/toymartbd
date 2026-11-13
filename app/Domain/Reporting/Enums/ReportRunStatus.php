<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

/**
 * A scheduled report's run for one period:
 *   queued   claimed by the sweep, job on the `reports` queue
 *   sending  the job has started (a duplicate job sees this and does nothing)
 *   sent     every eligible recipient was mailed
 *   skipped  no recipient was eligible (inactive, or lost the permission) — nothing sent
 *   failed   the job stopped with an error; never retried automatically, so nobody who
 *            already got the email gets it twice
 */
enum ReportRunStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
