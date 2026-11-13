<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentBookingRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confirm order → dispatch, second half (DATABASE-DESIGN §4.2 "on success"), in ONE
 * transaction: lock the shipment → booked (consignment id, tracking code, charge) →
 * ShipmentBooked → Ordering marks the order shipped (stock consumed, `sale_out`,
 * customer-visible timeline row) in the same transaction → COMMIT → Finance writes
 * `courier_charge`.
 *
 * If the order side refuses (it was cancelled while the courier call was in flight, or
 * stock no longer covers a line), the transaction rolls back — but the courier HAS the
 * parcel booked, so the consignment is never dropped: a second transaction records it on
 * the shipment (`booked`, `failure_code = completion_failed`, not dispatched) and the
 * shipment shows in the retry queue. Retrying completes it without booking again.
 */
final readonly class CompleteShipmentBooking
{
    public function __construct(private ShipmentBookingRecorder $recorder) {}

    public function handle(int $shipmentId, BookingResult $result, ?int $userId = null): ?Shipment
    {
        try {
            return DB::transaction(function () use ($shipmentId, $result, $userId): ?Shipment {
                $locked = Shipment::query()->with('provider')->lockForUpdate()->find($shipmentId);
                if ($locked === null || $locked->isDispatched()
                    || ! in_array($locked->status, [ShipmentStatus::Pending, ShipmentStatus::Submitted, ShipmentStatus::Booked], true)) {
                    return $locked;
                }

                return $this->recorder->markBooked(
                    $locked,
                    $result->consignmentId,
                    $result->trackingCode,
                    $result->trackingUrl,
                    $result->charge,
                    $result->providerStatusRaw,
                    $result->response,
                    $userId,
                    'booking',
                );
            });
        } catch (Throwable $e) {
            Log::error('courier.booking.completion_failed', ['shipment_id' => $shipmentId, 'exception' => $e::class, 'message' => $e->getMessage()]);

            return $this->keepConsignment($shipmentId, $result, $userId, $e);
        }
    }

    /** The courier accepted but the order could not be shipped: never lose the consignment. */
    private function keepConsignment(int $shipmentId, BookingResult $result, ?int $userId, Throwable $cause): ?Shipment
    {
        return DB::transaction(static function () use ($shipmentId, $result, $userId, $cause): ?Shipment {
            $locked = Shipment::query()->lockForUpdate()->find($shipmentId);
            if ($locked === null || $locked->isDispatched()) {
                return $locked;
            }

            $attributes = array_filter([
                'consignment_id' => $result->consignmentId,
                'tracking_code' => $result->trackingCode,
                'tracking_url' => $result->trackingUrl,
                'charge' => $result->charge,
                'provider_status_raw' => $result->providerStatusRaw,
                'booking_response' => $result->response,
                'booked_by' => $userId,
            ], static fn (mixed $v): bool => $v !== null) + [
                'failure_code' => Shipment::FAILURE_COMPLETION,
                'failure_reason' => 'Booked with the courier, but the order could not be marked shipped.',
                'error_message' => mb_substr($cause->getMessage(), 0, 1000),
            ];

            if ($locked->status !== ShipmentStatus::Booked) {
                // Recorded directly: this is the courier's fact, not a workflow move to validate.
                $attributes['status'] = ShipmentStatus::Booked;
                $attributes['booked_at'] = now();
            }
            $locked->forceFill($attributes)->save();

            return $locked;
        });
    }
}
