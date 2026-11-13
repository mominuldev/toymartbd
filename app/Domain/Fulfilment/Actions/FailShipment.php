<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * Booking did not happen (§4.2 "on failure"): shipment → failed with a machine-readable
 * code and the reason, so it shows in the retry queue. The order is untouched — it stays
 * confirmed/processing/ready_to_ship with its stock still reserved. A shipment that is no
 * longer pending or submitted (it got booked meanwhile) is left alone.
 */
final readonly class FailShipment
{
    public function __construct(private ShipmentTransitions $transitions) {}

    /** @param  array<string, mixed>|null  $response */
    public function handle(int $shipmentId, string $code, string $message, ?string $providerStatusRaw = null, ?array $response = null): ?Shipment
    {
        return DB::transaction(function () use ($shipmentId, $code, $message, $providerStatusRaw, $response): ?Shipment {
            $locked = Shipment::query()->lockForUpdate()->find($shipmentId);
            if ($locked === null || ! in_array($locked->status, [ShipmentStatus::Pending, ShipmentStatus::Submitted], true)) {
                return null;
            }

            return $this->transitions->move($locked, ShipmentStatus::Failed, 'booking', array_filter([
                'failure_code' => $code,
                'failure_reason' => mb_substr($message, 0, 255),
                'error_message' => $message,
                'provider_status_raw' => $providerStatusRaw,
                'booking_response' => $response,
            ], static fn (mixed $v): bool => $v !== null));
        });
    }
}
