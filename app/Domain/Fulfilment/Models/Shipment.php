<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Models;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\ShipmentViolation;
use App\Support\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to send an order with a courier (DATABASE-DESIGN §3.6, §4.2). Never deleted:
 * it is the trail behind a `courier_charge` and a stock movement. `status` moves only
 * through {@see ShipmentStatus::allowedTransitions()} via the Fulfilment actions.
 *
 * Fulfilment does not depend on Ordering (deptrac), so there is no `order()` relation:
 * the HTTP layer loads the order by `order_id` when it needs one.
 *
 * @property int $id
 * @property int $order_id
 * @property int $provider_id
 * @property string $number
 * @property string|null $consignment_id
 * @property string|null $tracking_code
 * @property string|null $tracking_url
 * @property ShipmentStatus $status
 * @property string|null $provider_status_raw
 * @property int|null $courier_location_id
 * @property int $weight_grams
 * @property int $item_count
 * @property Money $cod_amount
 * @property Money $declared_value
 * @property Money|null $charge
 * @property Money|null $cod_fee
 * @property Money|null $return_charge
 * @property Money|null $total_cost
 * @property Money|null $cod_collected
 * @property Carbon|null $pickup_requested_at
 * @property Carbon|null $booked_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $picked_up_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $returned_at
 * @property Carbon|null $cancelled_at
 * @property int $delivery_attempts
 * @property int $booking_attempts
 * @property Carbon|null $last_attempt_at
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property string|null $recipient_signature
 * @property string|null $label_path
 * @property array<string, mixed>|null $booking_payload
 * @property array<string, mixed>|null $booking_response
 * @property string|null $error_message
 * @property string|null $note
 * @property int|null $requested_by
 * @property int|null $booked_by
 * @property string|null $rider_name
 * @property string|null $rider_phone E.164 (rule 30); shown in full only with customers.view_pii
 * @property string|null $rider_source `manual` (staff typed it when the rider called) | `courier`
 * @property Carbon|null $rider_recorded_at
 * @property int|null $rider_recorded_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read CourierProvider $provider
 */
final class Shipment extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        self::deleting(static function (): void {
            throw ShipmentViolation::neverDeleted();
        });
    }

    /** @return BelongsTo<CourierProvider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(CourierProvider::class, 'provider_id');
    }

    /**
     * Shipments that block a new one for their order (anything but failed / cancelled).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [ShipmentStatus::Failed->value, ShipmentStatus::Cancelled->value]);
    }

    /**
     * The retry queue: booking failed, or the courier accepted but completing the order
     * locally failed (`failure_code = completion_failed`; the consignment is kept).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(static fn (Builder $q) => $q
            ->where('status', ShipmentStatus::Failed->value)
            ->orWhere(static fn (Builder $w) => $w->whereNull('dispatched_at')->where('failure_code', self::FAILURE_COMPLETION)));
    }

    /** The courier accepted the parcel and the order was marked shipped for it. */
    public function isDispatched(): bool
    {
        return $this->dispatched_at !== null;
    }

    public const string FAILURE_UNVERIFIED = 'unverified_provider';

    public const string FAILURE_INACTIVE = 'inactive_provider';

    public const string FAILURE_UNMAPPED = 'unmapped_location';

    public const string FAILURE_AMBIGUOUS = 'ambiguous_location';

    public const string FAILURE_REFUSED = 'provider_refused';

    public const string FAILURE_TRANSPORT = 'transport';

    public const string FAILURE_ORDER_STATE = 'order_not_shippable';

    public const string FAILURE_NO_BULK = 'bulk_unsupported';

    /** Courier accepted, but marking the order shipped failed — retry completes it without a second booking. */
    public const string FAILURE_COMPLETION = 'completion_failed';

    /** Staff recorded the rider when the rider called (neither courier's API returns the number). */
    public const string RIDER_MANUAL = 'manual';

    /** Reserved: a provider payload carried the rider (none of today's official APIs does). */
    public const string RIDER_COURIER = 'courier';

    /** @return array<string, string> */
    protected function casts(): array
    {
        $money = array_fill_keys([
            'cod_amount', 'declared_value', 'charge', 'cod_fee', 'return_charge', 'total_cost', 'cod_collected',
        ], MoneyCast::class);

        return $money + [
            'order_id' => 'integer',
            'provider_id' => 'integer',
            'status' => ShipmentStatus::class,
            'courier_location_id' => 'integer',
            'weight_grams' => 'integer',
            'item_count' => 'integer',
            'pickup_requested_at' => 'datetime',
            'booked_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivery_attempts' => 'integer',
            'booking_attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'booking_payload' => 'array',
            'booking_response' => 'array',
            'requested_by' => 'integer',
            'booked_by' => 'integer',
            'rider_recorded_at' => 'datetime',
            'rider_recorded_by' => 'integer',
        ];
    }
}
