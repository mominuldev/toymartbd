<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Models;

use App\Domain\Ordering\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The shopper's browser context at checkout (`order_tracking_contexts`). IP, user agent and
 * click ids are personal data: never logged, never returned by any API, and blanked after
 * `tracking.context_retention_days` by `toymart:tracking:prune-contexts` (the only update
 * this row ever gets). Never deleted.
 *
 * @property int $id
 * @property int $order_id
 * @property string|null $client_ip
 * @property string|null $user_agent
 * @property string|null $ga_client_id
 * @property string|null $ga_session_id
 * @property string|null $fbp
 * @property string|null $fbc
 * @property string|null $event_source_url
 * @property array{analytics?: bool, ads?: bool, mode?: string, policy_version?: string|null}|null $consent
 * @property Carbon|null $redacted_at
 * @property Carbon|null $created_at
 * @property-read Order $order
 */
final class OrderTrackingContext extends Model
{
    public const UPDATED_AT = null;

    /** Personal data the prune command blanks. */
    public const array PERSONAL_COLUMNS = ['client_ip', 'user_agent', 'ga_client_id', 'ga_session_id', 'fbp', 'fbc', 'event_source_url'];

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['client_ip', 'user_agent', 'fbp', 'fbc', 'ga_client_id', 'ga_session_id'];

    protected static function booted(): void
    {
        self::updating(static function (self $context): void {
            $changed = array_diff(array_keys($context->getDirty()), [...self::PERSONAL_COLUMNS, 'redacted_at']);
            if ($changed !== []) {
                throw new LogicException('order_tracking_contexts only changes when its personal data is redacted.');
            }
        });
        self::deleting(static function (): never {
            throw new LogicException('order_tracking_contexts is never deleted; it is redacted.');
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'consent' => 'array',
            'redacted_at' => 'datetime',
        ];
    }
}
