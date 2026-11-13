<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Reporting\Enums\ReportRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One period's send of a schedule — the idempotency record (UNIQUE schedule + period).
 *
 * @property int $id
 * @property int $report_schedule_id
 * @property Carbon $period_from
 * @property Carbon $period_to
 * @property ReportRunStatus $status
 * @property int $recipients_sent
 * @property int $recipients_skipped
 * @property string|null $error
 * @property Carbon|null $due_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ReportSchedule $schedule
 */
final class ReportScheduleRun extends Model
{
    /** @var list<string> */
    protected $fillable = ['report_schedule_id', 'period_from', 'period_to', 'status', 'recipients_sent', 'recipients_skipped', 'error', 'due_at', 'sent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'status' => ReportRunStatus::class,
            'recipients_sent' => 'integer',
            'recipients_skipped' => 'integer',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReportSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }
}
