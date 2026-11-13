<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\InvalidShipmentTransition;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Cancel a shipment that never reached a courier: `pending` or `failed` only. Nothing left
 * the warehouse and no courier holds it, so this is a local status change; the order keeps
 * its reservation and can be requested again.
 *
 * A `submitted` or booked parcel is NOT cancellable here: the courier holds it and the
 * driver contract has no cancel call — cancel it with the courier, and it comes back as a
 * courier return through ApplyCourierStatus. Refused as a 409.
 */
final readonly class CancelShipment
{
    public const int REASON_MAX = 255;

    public function __construct(private ShipmentTransitions $transitions) {}

    /** @throws InvalidShipmentTransition */
    public function handle(Shipment $shipment, ?string $reason = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $reason): Shipment {
            /** @var Shipment $locked */
            $locked = Shipment::query()->with('provider')->lockForUpdate()->findOrFail($shipment->id);
            if (! in_array($locked->status, [ShipmentStatus::Pending, ShipmentStatus::Failed], true)) {
                throw InvalidShipmentTransition::between($locked->status, ShipmentStatus::Cancelled);
            }

            return $this->transitions->move($locked, ShipmentStatus::Cancelled, 'manual', array_filter([
                'cancelled_at' => now(),
                'failure_reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, self::REASON_MAX),
            ], static fn (mixed $v): bool => $v !== null));
        });
    }
}
