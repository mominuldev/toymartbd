<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Mail\ScheduledReportMail;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Domain\Reporting\Schedules\ScheduledReport;
use App\Domain\Reporting\Support\DateRange;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one claimed run (queued on `reports` by the sweep). No database transaction is
 * held while mail goes out (rule 4's spirit: no external I/O inside a transaction).
 *
 * Exactly-once per period: the run moves queued → sending with a conditional UPDATE, so a
 * duplicated job finds nothing to do; a run that fails is marked failed and never resent
 * automatically. Each recipient gets the report built for their own permissions; one who is
 * inactive or no longer holds the report's permissions is skipped, never sent a cut-down copy
 * of a report they may not see.
 */
final readonly class SendReportRun
{
    public function __construct(private ScheduledReport $reports) {}

    public function handle(int $runId): ReportRunStatus
    {
        $started = ReportScheduleRun::query()
            ->whereKey($runId)
            ->where('status', ReportRunStatus::Queued->value)
            ->update(['status' => ReportRunStatus::Sending->value, 'updated_at' => now()]);
        $run = ReportScheduleRun::query()->with('schedule.recipients')->find($runId);
        if ($started === 0 || $run === null) {
            return $run === null ? ReportRunStatus::Skipped : $run->status;
        }

        $schedule = $run->schedule;
        $range = DateRange::custom($run->period_from->toDateString(), $run->period_to->toDateString());
        $locale = $schedule->locale === 'bn' ? 'bn' : 'en';
        $sent = 0;
        $skipped = 0;
        /** @var array<string, array<string, mixed>> $built one build per visibility */
        $built = [];

        try {
            foreach ($schedule->recipients as $recipient) {
                if (! $schedule->report_type->canReceive($recipient)) {
                    $skipped++;

                    continue;
                }
                $withProfit = ScheduledReport::canSeeProfit($recipient);
                $key = $withProfit ? 'profit' : 'plain';
                $built[$key] ??= $this->reports->build($schedule->report_type, $range, $withProfit, $locale);

                Mail::to($recipient->email, $recipient->name)->send(new ScheduledReportMail($schedule, $range, $built[$key], $locale));
                $sent++;
            }
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => ReportRunStatus::Failed,
                'recipients_sent' => $sent,
                'recipients_skipped' => $skipped,
                'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500),
            ])->save();
            report($e);

            return ReportRunStatus::Failed;
        }

        $status = $sent === 0 ? ReportRunStatus::Skipped : ReportRunStatus::Sent;
        $run->forceFill([
            'status' => $status,
            'recipients_sent' => $sent,
            'recipients_skipped' => $skipped,
            'sent_at' => $sent === 0 ? null : DhakaDate::instant(),
        ])->save();

        return $status;
    }
}
