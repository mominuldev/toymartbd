<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Enums\AlertSeverity;
use App\Domain\Monitoring\Enums\AlertState;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The current state of one business alert (P10). Written only by RecordAlertOutcome.
 *
 * @property string $name
 * @property AlertState $state
 * @property AlertSeverity|null $severity
 * @property string|null $summary
 * @property Carbon|null $fired_at
 * @property Carbon|null $last_notified_at
 * @property Carbon|null $resolved_at
 * @property int $notify_count
 * @property Carbon|null $last_checked_at
 * @property Carbon|null $last_test_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(name: 'monitoring_alerts', key: 'name', keyType: 'string', incrementing: false)]
class MonitoringAlert extends Model
{
    /** @var list<string> */
    protected $fillable = ['name'];

    /** @var array<string, mixed> */
    protected $attributes = ['state' => 'ok', 'notify_count' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => AlertState::class,
            'severity' => AlertSeverity::class,
            'fired_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_test_at' => 'datetime',
            'notify_count' => 'integer',
        ];
    }
}
