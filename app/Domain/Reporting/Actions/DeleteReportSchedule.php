<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Models\ReportSchedule;
use Illuminate\Support\Facades\DB;

/** Deletes a schedule with its recipients and its sent-log (cascade). Nothing financial lives here. */
final readonly class DeleteReportSchedule
{
    public function handle(ReportSchedule $schedule): void
    {
        DB::transaction(static function () use ($schedule): void {
            $schedule->recipients()->detach();
            $schedule->runs()->delete();
            $schedule->delete();
        });
    }
}
