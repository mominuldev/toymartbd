<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Notification\Enums\AutomationKind;
use App\Domain\Notification\Enums\AutomationSkip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One automation decision (P8): `queued` (a notification log row exists) or `skipped` with
 * the reason. UNIQUE (automation, subject_id, period) — at most one per order / per
 * customer-year, however often the scheduler runs.
 *
 * @property int $id
 * @property AutomationKind $automation
 * @property int $subject_id
 * @property string $period
 * @property int|null $customer_id
 * @property string $status
 * @property AutomationSkip|null $skip_reason
 * @property int|null $notification_log_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AutomationSend extends Model
{
    public const string QUEUED = 'queued';

    public const string SKIPPED = 'skipped';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'automation' => AutomationKind::class,
            'skip_reason' => AutomationSkip::class,
            'subject_id' => 'integer',
            'customer_id' => 'integer',
            'notification_log_id' => 'integer',
        ];
    }
}
