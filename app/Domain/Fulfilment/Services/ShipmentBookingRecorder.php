<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Events\ShipmentBooked;
use App\Domain\Fulfilment\Models\Shipment;
use App\Support\Money;

/**
 * The in-transaction half of "the courier accepted" (§4.2 "on success"), shared by the
 * booking job (single booking) and ApplyCourierStatus (a bulk-submitted parcel confirmed
 * by webhook/poll): shipment → booked with the courier's ids and charge, `dispatched_at`
 * stamped, then ShipmentBooked — whose synchronous Ordering listener marks the order
 * shipped in the SAME transaction and whose queued Finance listener writes
 * `courier_charge` after commit. No transaction of its own; the caller holds the row lock.
 */
final readonly class ShipmentBookingRecorder
{
    public function __construct(private ShipmentTransitions $transitions) {}

    /**
     * @param  array<string, mixed>|null  $response
     */
    public function markBooked(
        Shipment $locked,
        ?string $consignmentId,
        ?string $trackingCode,
        ?string $trackingUrl,
        ?Money $charge,
        ?string $providerStatusRaw,
        ?array $response,
        ?int $userId,
        string $source,
    ): Shipment {
        $attributes = array_filter([
            'consignment_id' => $consignmentId ?? $locked->consignment_id,
            'tracking_code' => $trackingCode ?? $locked->tracking_code,
            'tracking_url' => $trackingUrl ?? $locked->tracking_url,
            'charge' => $charge ?? $locked->charge,
            'provider_status_raw' => $providerStatusRaw ?? $locked->provider_status_raw,
            'booking_response' => $response ?? $locked->booking_response,
            'booked_by' => $userId ?? $locked->booked_by,
        ], static fn (mixed $v): bool => $v !== null);
        $charge ??= $locked->charge;
        $attributes['total_cost'] = $charge;
        $attributes['dispatched_at'] = now();
        $attributes['failure_code'] = null;
        $attributes['failure_reason'] = null;
        $attributes['error_message'] = null;

        if ($locked->status === ShipmentStatus::Booked) {
            // A completion retry: the consignment was recorded earlier, the order was not shipped.
            $locked->forceFill($attributes)->save();
        } else {
            $this->transitions->move($locked, ShipmentStatus::Booked, $source, $attributes);
        }

        $locked->loadMissing('provider');
        ShipmentBooked::dispatch(
            $locked->id,
            $locked->order_id,
            $locked->number,
            $locked->provider->code,
            $locked->consignment_id,
            $locked->tracking_code,
            $locked->charge?->toDecimal(),
            ($locked->booked_at ?? now())->toIso8601String(),
            $userId,
        );

        return $locked;
    }
}
