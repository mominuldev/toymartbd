<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Models\Order;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Enums\TrackingSource;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One relayed commerce event and its dispatch result per destination (DATABASE-DESIGN
 * `tracking_events`, PRD K-01..K-03). Written by {@see \App\Domain\Tracking\Actions\RecordTrackingEvent};
 * dispatched by {@see \App\Domain\Tracking\Jobs\DispatchTrackingEvent}.
 *
 * Append-only except the dispatch columns ({@see self::DISPATCH_COLUMNS}); never deleted.
 * `payload` never holds raw PII (rule 20): items, value and URLs at ingest, plus — under
 * `sent.{ga4|meta}` — the body as sent with user data hashed and IP / user agent redacted.
 *
 * @property int $id
 * @property string $event_id
 * @property TrackingEventName $event_name
 * @property TrackingSource $source
 * @property int|null $customer_id
 * @property string|null $session_id
 * @property int|null $order_id
 * @property Money|null $value
 * @property string|null $currency
 * @property array<string, mixed>|null $payload
 * @property array{analytics?: bool, ads?: bool, mode?: string, policy_version?: string|null}|null $consent_state
 * @property DispatchStatus $ga4_status
 * @property array<string, mixed>|null $ga4_response
 * @property int $ga4_attempts
 * @property DispatchStatus $meta_status
 * @property array<string, mixed>|null $meta_response
 * @property int $meta_attempts
 * @property Carbon|null $occurred_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $created_at
 * @property-read Order|null $order
 * @property-read Customer|null $customer
 */
final class TrackingEvent extends Model
{
    public const UPDATED_AT = null;

    /** The only columns a dispatch may change after the row is written. */
    public const array DISPATCH_COLUMNS = [
        'ga4_status', 'ga4_response', 'ga4_attempts',
        'meta_status', 'meta_response', 'meta_attempts',
        'dispatched_at', 'payload',
    ];

    /** Destination key → its status / response / attempts columns. */
    public const array DESTINATIONS = ['ga4', 'meta'];

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'ga4_status' => 'pending',
        'meta_status' => 'pending',
        'ga4_attempts' => 0,
        'meta_attempts' => 0,
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $event): void {
            $changed = array_diff(array_keys($event->getDirty()), self::DISPATCH_COLUMNS);
            if ($changed !== []) {
                throw new LogicException('tracking_events is append-only; only dispatch columns may change.');
            }
        });
        self::deleting(static function (): never {
            throw new LogicException('tracking_events is never deleted.');
        });
    }

    public function statusFor(string $destination): DispatchStatus
    {
        $status = $this->getAttribute($destination.'_status');

        return $status instanceof DispatchStatus ? $status : DispatchStatus::Pending;
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_name' => TrackingEventName::class,
            'source' => TrackingSource::class,
            'customer_id' => 'integer',
            'order_id' => 'integer',
            'value' => MoneyCast::class,
            'payload' => 'array',
            'consent_state' => 'array',
            'ga4_status' => DispatchStatus::class,
            'ga4_response' => 'array',
            'ga4_attempts' => 'integer',
            'meta_status' => DispatchStatus::class,
            'meta_response' => 'array',
            'meta_attempts' => 'integer',
            'occurred_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }
}
