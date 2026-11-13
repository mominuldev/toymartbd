<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Models\ReportScheduleRun;
use App\Domain\Reporting\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Claims one period of a schedule by inserting its run row. The UNIQUE (schedule, period)
 * key makes the insert the idempotency check: a second sweep, a re-run of the command or a
 * second server inserts nothing and gets null. A single INSERT needs no transaction.
 */
final readonly class ClaimReportRun
{
    public function handle(ReportSchedule $schedule, DateRange $range, CarbonImmutable $dueAt): ?ReportScheduleRun
    {
        $now = now();
        $inserted = DB::table('report_schedule_runs')->insertOrIgnore([
            'report_schedule_id' => $schedule->id,
            'period_from' => $range->fromDate(),
            'period_to' => $range->toDate(),
            'status' => ReportRunStatus::Queued->value,
            'due_at' => $dueAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($inserted === 0) {
            return null;
        }

        return ReportScheduleRun::query()
            ->where('report_schedule_id', $schedule->id)
            ->whereDate('period_from', $range->fromDate())
            ->whereDate('period_to', $range->toDate())
            ->first();
    }
}
