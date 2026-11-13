<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\Reporting\Enums\ReportFileFormat;
use App\Domain\Reporting\Enums\ReportFrequency;
use App\Domain\Reporting\Enums\ReportType;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A scheduled report email (P8). Recipients are staff users only.
 *
 * @property int $id
 * @property string $name
 * @property ReportType $report_type
 * @property ReportFrequency $frequency
 * @property string $send_time HH:MM, Asia/Dhaka
 * @property string $locale en|bn
 * @property ReportFileFormat $format
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $recipients
 * @property-read Collection<int, ReportScheduleRun> $runs
 * @property-read ReportScheduleRun|null $latestRun
 */
final class ReportSchedule extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'report_type', 'frequency', 'send_time', 'locale', 'format', 'is_active', 'created_by', 'updated_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'frequency' => ReportFrequency::class,
            'format' => ReportFileFormat::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function recipients(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'report_schedule_recipients', 'report_schedule_id', 'user_id')->orderBy('users.name');
    }

    /** @return HasMany<ReportScheduleRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportScheduleRun::class)->orderByDesc('period_from')->orderByDesc('id');
    }

    /** @return HasOne<ReportScheduleRun, $this> */
    public function latestRun(): HasOne
    {
        return $this->hasOne(ReportScheduleRun::class)->latestOfMany();
    }
}
