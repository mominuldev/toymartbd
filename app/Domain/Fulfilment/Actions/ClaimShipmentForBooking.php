<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * The idempotency gate before a courier call: lock the shipment row, re-check it is still
 * `pending` (a second run of the same job, or a job racing a manual cancel, sees anything
 * else and stops), count the attempt and store what is about to be sent. COMMIT — the
 * call itself happens after, outside any transaction (rule 4).
 *
 * The stored payload carries no phone and no address (rule 20).
 */
final readonly class ClaimShipmentForBooking
{
    /** @return Shipment|null null when the shipment is no longer pending */
    public function handle(int $shipmentId, ShipmentRequest $request): ?Shipment
    {
        return DB::transaction(static function () use ($shipmentId, $request): ?Shipment {
            $locked = Shipment::query()->lockForUpdate()->find($shipmentId);
            if ($locked === null || $locked->status !== ShipmentStatus::Pending) {
                return null;
            }

            $locked->forceFill([
                'booking_attempts' => $locked->booking_attempts + 1,
                'last_attempt_at' => now(),
                'booking_payload' => self::payload($request),
            ])->save();

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    public static function payload(ShipmentRequest $request): array
    {
        return [
            'merchant_order_ref' => $request->merchantOrderRef,
            'cod_amount' => $request->codAmount->toDecimal(),
            'declared_value' => $request->declaredValue->toDecimal(),
            'weight_grams' => $request->weightGrams,
            'item_count' => $request->itemCount,
            'district_id' => $request->districtId,
            'upazila_id' => $request->upazilaId,
            'courier_location' => $request->courierLocation === null ? null : [
                'city' => $request->courierLocation->externalCityId,
                'zone' => $request->courierLocation->externalZoneId,
                'area' => $request->courierLocation->externalAreaId,
            ],
        ];
    }
}
