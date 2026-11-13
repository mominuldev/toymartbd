<?php

declare(strict_types=1);

namespace App\Console\Commands\Reporting;

use App\Domain\Reporting\Actions\ClaimReportRun;
use App\Domain\Reporting\Jobs\SendScheduledReport;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Domain\Reporting\Schedules\ScheduleClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The scheduled-report sweep (P8), every 15 minutes. For each active schedule whose latest
 * occurrence has passed, claims that period (one row per schedule + period) and queues the
 * send on `reports`. Idempotent: a period already claimed is skipped, so running the sweep
 * twice — or on two servers — never sends twice.
 */
final class SendScheduledReportsCommand extends Command
{
    protected $signature = 'toymart:reports:send-scheduled';

    protected $description = 'Queue every scheduled report email that is due (once per schedule and period)';

    public function handle(ClaimReportRun $claim): int
    {
        $now = CarbonImmutable::now('UTC');
        $queued = 0;

        ReportSchedule::query()->where('is_active', true)->orderBy('id')->each(function (ReportSchedule $schedule) use ($claim, $now, &$queued): void {
            $due = ScheduleClock::due($schedule, $now);
            if ($due === null) {
                return;
            }
            $run = $claim->handle($schedule, $due['range'], $due['due_at']);
            if ($run === null) {
                return;
            }
            SendScheduledReport::dispatch($run->id);
            $queued++;
            $this->line("Queued schedule {$schedule->id} for {$due['range']->fromDate()}..{$due['range']->toDate()}.");
        });

        $this->info("{$queued} scheduled report(s) queued.");

        return self::SUCCESS;
    }
}
