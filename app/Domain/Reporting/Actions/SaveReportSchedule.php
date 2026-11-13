<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\DTOs\ReportScheduleData;
use App\Domain\Reporting\Models\ReportSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a report schedule and replaces its recipients, in one transaction.
 * The FormRequest has already checked that every recipient is an active staff member who
 * may receive this report type; this Action trusts nothing else.
 */
final readonly class SaveReportSchedule
{
    public function handle(ReportScheduleData $data, User $actor, ?ReportSchedule $schedule = null): ReportSchedule
    {
        return DB::transaction(function () use ($data, $actor, $schedule): ReportSchedule {
            $schedule ??= new ReportSchedule(['created_by' => $actor->id]);
            $schedule->fill([
                'name' => $data->name,
                'report_type' => $data->reportType,
                'frequency' => $data->frequency,
                'send_time' => $data->sendTime,
                'locale' => $data->locale,
                'format' => $data->format,
                'is_active' => $data->isActive,
                'updated_by' => $actor->id,
            ]);
            $schedule->save();
            $schedule->recipients()->sync(array_values(array_unique($data->recipientIds)));

            return $schedule->load(['recipients', 'latestRun']);
        });
    }
}
