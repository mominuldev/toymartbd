<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Reporting\Enums\ReportDeliveryStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One recipient's copy of a run (P9) — UNIQUE (run, user). A resend mails only the copies
 * that are not `sent`.
 *
 * @property int $id
 * @property int $report_schedule_run_id
 * @property int $user_id
 * @property ReportDeliveryStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $sent_at
 * @property int|null $resent_by
 * @property Carbon|null $resent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $resender
 */
final class ReportScheduleRunDelivery extends Model
{
    /** @var list<string> */
    protected $fillable = ['report_schedule_run_id', 'user_id', 'status', 'attempts', 'error', 'sent_at', 'resent_by', 'resent_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReportDeliveryStatus::class,
            'attempts' => 'integer',
            'user_id' => 'integer',
            'resent_by' => 'integer',
            'sent_at' => 'datetime',
            'resent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resent_by');
    }
}
