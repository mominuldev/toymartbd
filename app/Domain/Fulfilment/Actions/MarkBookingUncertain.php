<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * The courier may or may not have created the consignment (an unreadable 2xx). Re-posting
 * could book the parcel twice, so the shipment moves to `submitted` — "with the courier,
 * id unknown", exactly like a bulk submission (rule 24d) — and waits for a lookup by
 * merchant reference, the webhook or the poll to confirm it (ApplyCourierStatus). The
 * order is untouched until then. If nothing ever confirms it, staff see the note and
 * check the courier portal.
 */
final readonly class MarkBookingUncertain
{
    public function __construct(private ShipmentTransitions $transitions) {}

    public function handle(int $shipmentId, string $message): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $message): ?Shipment {
            $locked = Shipment::query()->lockForUpdate()->find($shipmentId);
            if ($locked === null || $locked->status !== ShipmentStatus::Pending) {
                return $locked;
            }

            return $this->transitions->move($locked, ShipmentStatus::Submitted, 'booking', [
                'failure_reason' => 'Booking outcome unknown; awaiting courier confirmation — do not re-book.',
                'error_message' => mb_substr($message, 0, 1000),
            ]);
        });
    }
}
