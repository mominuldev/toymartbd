<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\InvalidShipmentTransition;
use App\Domain\Fulfilment\Jobs\BookShipment;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * The retry queue's action (§4.2 "admin sees it in a retry queue"):
 *
 *   - `failed` → `pending` (same shipment, same number, so the courier sees the same
 *     merchant reference) and BookShipment is queued again after commit;
 *   - `booked` but never dispatched (`completion_failed`: the courier accepted, the order
 *     side refused) → the completion is re-run locally — the courier is NOT called again.
 *
 * Anything else is a 409.
 */
final readonly class RetryShipment
{
    public function __construct(
        private ShipmentTransitions $transitions,
        private CompleteShipmentBooking $complete,
    ) {}

    /** @throws InvalidShipmentTransition */
    public function handle(Shipment $shipment, ?int $userId = null): Shipment
    {
        if ($shipment->status === ShipmentStatus::Booked && ! $shipment->isDispatched() && $shipment->failure_code === Shipment::FAILURE_COMPLETION) {
            $done = $this->complete->handle($shipment->id, new BookingResult(true, $shipment->consignment_id, $shipment->tracking_code, $shipment->charge), $userId);

            return ($done ?? $shipment)->refresh()->load('provider');
        }

        $retried = DB::transaction(function () use ($shipment): Shipment {
            /** @var Shipment $locked */
            $locked = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            return $this->transitions->move($locked, ShipmentStatus::Pending, 'retry', [
                'failure_code' => null,
                'failure_reason' => null,
                'error_message' => null,
            ]);
        });

        BookShipment::dispatch($retried->id, $userId);

        return $retried->refresh()->load('provider');
    }
}
