<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Models;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One status a shipment entered (DATABASE-DESIGN §3.6 `courier_tracking_events`). Written
 * only by {@see \App\Domain\Fulfilment\Listeners\RecordCourierTrackingEvent}; never updated
 * or deleted — the history is evidence.
 *
 * @property int $id
 * @property int $shipment_id
 * @property int $provider_id
 * @property ShipmentStatus $normalised_status
 * @property string|null $from_status
 * @property string|null $provider_status
 * @property string|null $message
 * @property string|null $location
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property array<string, mixed>|null $payload
 * @property string $source
 * @property Carbon|null $created_at
 */
final class CourierTrackingEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('courier_tracking_events is append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('courier_tracking_events is append-only.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'shipment_id' => 'integer',
            'provider_id' => 'integer',
            'normalised_status' => ShipmentStatus::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'payload' => 'array',
        ];
    }
}
