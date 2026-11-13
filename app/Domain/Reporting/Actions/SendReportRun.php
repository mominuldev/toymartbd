<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Enums\ReportDeliveryStatus;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Mail\ScheduledReportMail;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Domain\Reporting\Models\ReportScheduleRunDelivery;
use App\Domain\Reporting\Schedules\ScheduledReport;
use App\Domain\Reporting\Support\DateRange;
use App\Models\User;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one claimed (or resent) run, queued on `reports`. No database transaction is held
 * while mail goes out (rule 4's spirit: no external I/O inside a transaction).
 *
 * Exactly-once per period and recipient: the run moves queued → sending with a conditional
 * UPDATE, so a duplicated job finds nothing to do; each recipient's copy is recorded in
 * `report_schedule_run_deliveries` (P9), and a copy already `sent` — or `skipped` on an
 * earlier attempt — is never mailed again. One recipient's failure no longer stops the rest:
 * their copy is marked failed and the run ends `failed`, so staff can resend just the failed
 * copies (ResendReportRun). Nothing is retried automatically.
 *
 * Each recipient gets the report built for their own permissions, checked at send time —
 * on a resend too; one who is inactive or no longer holds the report's permissions is
 * skipped, never sent a cut-down copy of a report they may not see.
 */
final readonly class SendReportRun
{
    public function __construct(private ScheduledReport $reports) {}

    /** @param  int|null  $onlyUserId  a resend of one recipient's copy (P9); null = every copy still due */
    public function handle(int $runId, ?int $onlyUserId = null): ReportRunStatus
    {
        $started = ReportScheduleRun::query()
            ->whereKey($runId)
            ->where('status', ReportRunStatus::Queued->value)
            ->update(['status' => ReportRunStatus::Sending->value, 'updated_at' => now()]);
        $run = ReportScheduleRun::query()->with(['schedule.recipients', 'deliveries'])->find($runId);
        if ($started === 0 || $run === null) {
            return $run === null ? ReportRunStatus::Skipped : $run->status;
        }

        $schedule = $run->schedule;
        $range = DateRange::custom($run->period_from->toDateString(), $run->period_to->toDateString());
        $locale = $schedule->locale === 'bn' ? 'bn' : 'en';
        $copies = $run->deliveries->keyBy('user_id');
        /** @var array<string, array<string, mixed>> $built one build per visibility */
        $built = [];
        $sentNow = false;

        foreach ($schedule->recipients as $recipient) {
            $copy = $copies->get($recipient->id);
            if ($onlyUserId !== null && $recipient->id !== $onlyUserId) {
                continue;
            }
            // Already mailed, or skipped on an earlier attempt: never again for this period.
            if ($copy !== null && in_array($copy->status, [ReportDeliveryStatus::Sent, ReportDeliveryStatus::Skipped], true)) {
                continue;
            }
            if (! $schedule->report_type->canReceive($recipient)) {
                $this->record($run, $recipient, $copy, ReportDeliveryStatus::Skipped);

                continue;
            }

            try {
                $withProfit = ScheduledReport::canSeeProfit($recipient);
                $key = $withProfit ? 'profit' : 'plain';
                $built[$key] ??= $this->reports->build($schedule->report_type, $range, $withProfit, $locale);

                Mail::to($recipient->email, $recipient->name)->send(new ScheduledReportMail($schedule, $range, $built[$key], $locale));
            } catch (Throwable $e) {
                $this->record($run, $recipient, $copy, ReportDeliveryStatus::Failed, self::safeError($e));
                report($e);

                continue;
            }
            $this->record($run, $recipient, $copy, ReportDeliveryStatus::Sent);
            $sentNow = true;
        }

        // A one-recipient resend for someone no longer on the schedule: their copy is closed.
        if ($onlyUserId !== null && ! $schedule->recipients->contains('id', $onlyUserId)) {
            $copy = $copies->get($onlyUserId);
            if ($copy !== null && $copy->status !== ReportDeliveryStatus::Sent) {
                $copy->forceFill(['status' => ReportDeliveryStatus::Skipped])->save();
            }
        }

        return $this->settle($run, $sentNow);
    }

    /** The run's figures and status, from its recipients' copies. */
    private function settle(ReportScheduleRun $run, bool $sentNow): ReportRunStatus
    {
        $copies = ReportScheduleRunDelivery::query()->where('report_schedule_run_id', $run->id)->orderBy('id')->get();
        $sent = $copies->where('status', ReportDeliveryStatus::Sent)->count();
        $skipped = $copies->where('status', ReportDeliveryStatus::Skipped)->count();
        $failed = $copies->where('status', ReportDeliveryStatus::Failed);

        $status = match (true) {
            $failed->isNotEmpty() => ReportRunStatus::Failed,
            $sent > 0 => ReportRunStatus::Sent,
            default => ReportRunStatus::Skipped,
        };
        $run->forceFill([
            'status' => $status,
            'recipients_sent' => $sent,
            'recipients_skipped' => $skipped,
            'error' => $failed->first()?->error,
            'sent_at' => $sentNow ? DhakaDate::instant() : $run->sent_at,
        ])->save();

        return $status;
    }

    private function record(ReportScheduleRun $run, User $recipient, ?ReportScheduleRunDelivery $copy, ReportDeliveryStatus $status, ?string $error = null): void
    {
        $copy ??= new ReportScheduleRunDelivery(['report_schedule_run_id' => $run->id, 'user_id' => $recipient->id, 'attempts' => 0]);
        $copy->forceFill([
            'status' => $status,
            'attempts' => $copy->attempts + ($status === ReportDeliveryStatus::Skipped ? 0 : 1),
            'error' => $error,
            'sent_at' => $status === ReportDeliveryStatus::Sent ? DhakaDate::instant() : $copy->sent_at,
        ])->save();
    }

    /** "TransportException: …", capped, with any email address masked (rule 20). */
    private static function safeError(Throwable $e): string
    {
        $message = (string) preg_replace('/[^\s<>"\'@]+@[^\s<>"\'@]+/u', '[email]', $e->getMessage());

        return mb_substr(class_basename($e).': '.$message, 0, 500);
    }
}
