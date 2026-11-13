<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Enums\ReportDeliveryStatus;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Exceptions\ReportRunNotResendable;
use App\Domain\Reporting\Jobs\SendScheduledReport;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Domain\Reporting\Models\ReportScheduleRunDelivery;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff resend of a FAILED scheduled-report run for the same period (P9, Phase 8 closeout):
 * every copy that failed (or never went out), or one recipient's copy.
 *
 * Exact and idempotent:
 *   - the run row is locked FOR UPDATE; a run already queued or sending (a resend just asked
 *     for, by anyone) is left alone and nothing more is queued — a double click sends once;
 *   - a copy already `sent` is never mailed again (409 when asked for by name);
 *   - recipients are re-checked against the report's permissions now (and again by the send
 *     job): one who may no longer see the report is not queued;
 *   - a run from before per-recipient records with some copies sent cannot be resent as a
 *     whole (who already has it is unknown) — one recipient at a time instead.
 *
 * Writes the copies to `queued` with who resent them, the run to `queued` with its resend
 * count, and an `audit_sensitive` row (a report with financial data leaves the admin again).
 * The send job is dispatched on `reports` after the commit — no mail inside the transaction.
 */
final readonly class ResendReportRun
{
    public function handle(ReportSchedule $schedule, ReportScheduleRun $run, User $actor, ?int $userId = null): ReportScheduleRun
    {
        $queued = DB::transaction(function () use ($schedule, $run, $actor, $userId): bool {
            /** @var ReportScheduleRun $locked */
            $locked = ReportScheduleRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->status, [ReportRunStatus::Queued, ReportRunStatus::Sending], true)) {
                return false;
            }
            if ($locked->status !== ReportRunStatus::Failed) {
                throw ReportRunNotResendable::notFailed();
            }

            $copies = ReportScheduleRunDelivery::query()->where('report_schedule_run_id', $locked->id)->lockForUpdate()->get()->keyBy('user_id');
            $recipients = $schedule->recipients()->with('roles')->get();
            $eligible = $recipients->filter(static fn (User $u): bool => $schedule->report_type->canReceive($u));

            if ($userId !== null) {
                if ($copies->get($userId)?->status === ReportDeliveryStatus::Sent) {
                    throw ReportRunNotResendable::alreadySent();
                }
                $targets = $eligible->where('id', $userId)->pluck('id')->all();
            } else {
                if ($copies->isEmpty() && $locked->recipients_sent > 0) {
                    throw ReportRunNotResendable::unknownRecipients();
                }
                $targets = $eligible
                    ->filter(static function (User $u) use ($copies): bool {
                        $status = $copies->get($u->id)?->status;

                        return $status === null || $status === ReportDeliveryStatus::Failed || $status === ReportDeliveryStatus::Queued;
                    })
                    ->pluck('id')->all();
            }
            if ($targets === []) {
                throw ReportRunNotResendable::nothingToResend();
            }

            $now = now();
            foreach ($targets as $target) {
                $copy = $copies->get($target) ?? new ReportScheduleRunDelivery(['report_schedule_run_id' => $locked->id, 'user_id' => $target, 'attempts' => 0]);
                $copy->forceFill(['status' => ReportDeliveryStatus::Queued, 'resent_by' => $actor->id, 'resent_at' => $now])->save();
            }
            $before = ['status' => $locked->status->value, 'resend_count' => $locked->resend_count];
            $locked->forceFill([
                'status' => ReportRunStatus::Queued,
                'resend_count' => $locked->resend_count + 1,
                'last_resent_by' => $actor->id,
                'last_resent_at' => $now,
            ])->save();

            AuditSensitive::query()->create([
                'user_id' => $actor->id,
                'action' => AuditAction::ScheduledReportResent,
                'model_type' => $locked->getMorphClass(),
                'model_id' => $locked->id,
                'before' => $before,
                'after' => [
                    'status' => ReportRunStatus::Queued->value,
                    'resend_count' => $locked->resend_count,
                    'schedule_id' => $schedule->id,
                    'period_from' => $locked->period_from->toDateString(),
                    'period_to' => $locked->period_to->toDateString(),
                    // Staff user ids only — never an email address (rule 20).
                    'recipient_ids' => array_values($targets),
                    'scope' => $userId === null ? 'failed_copies' : 'one_recipient',
                ],
            ]);

            return true;
        });

        if ($queued) {
            SendScheduledReport::dispatch($run->id, $userId);
        }

        return $run->refresh();
    }
}
