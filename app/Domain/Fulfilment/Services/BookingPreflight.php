<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Contracts\ShippableOrders;
use App\Domain\Fulfilment\DTOs\CourierLocationRef;
use App\Domain\Fulfilment\DTOs\PreflightOutcome;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;

/**
 * Everything checked before a courier is called (read-only; runs in the booking job,
 * outside any transaction). In order:
 *
 *   1. the provider is active;
 *   2. in production, the provider is `verified` (rule 33a — no production booking through
 *      an unverified provider, e.g. Steadfast built from fixtures only);
 *   3. the order still exists and is shippable (it may have been cancelled since the
 *      request);
 *   4. when the provider or its driver needs location mapping (Pathao), exactly one
 *      explicitly mapped `courier_locations` row — the one staff chose on the request, or
 *      the only one mapped to the address's upazila (or district) — else the booking is
 *      blocked as `unmapped_location` / `ambiguous_location`. Never matched by name, never
 *      guessed (rule 32).
 *
 * Then builds the {@see ShipmentRequest}: the merchant reference is the shipment number.
 */
final readonly class BookingPreflight
{
    public function __construct(
        private ShippableOrders $orders,
        private BookingLocationPreview $locations,
    ) {}

    public function check(Shipment $shipment, CourierProvider $provider, CourierGateway $driver): PreflightOutcome
    {
        if (! $provider->is_active) {
            return PreflightOutcome::blocked(Shipment::FAILURE_INACTIVE, "Courier provider {$provider->name} is not active.");
        }
        if (! $provider->verified && self::isProduction()) {
            return PreflightOutcome::blocked(
                Shipment::FAILURE_UNVERIFIED,
                "Courier provider {$provider->name} is not verified; production booking is blocked until its driver has been verified against the live API (rule 33a).",
            );
        }

        $order = $this->orders->find($shipment->order_id);
        if ($order === null || ! $order->canShip) {
            return PreflightOutcome::blocked(
                Shipment::FAILURE_ORDER_STATE,
                'The order can no longer be shipped (status: '.($order->status ?? 'missing').').',
            );
        }

        $location = null;
        if ($provider->requires_location_mapping || $driver->requiresLocationMapping()) {
            $resolved = $this->location($shipment, $provider, $order->districtId, $order->upazilaId);
            if (! $resolved instanceof CourierLocationRef) {
                return $resolved;
            }
            $location = $resolved;
        }

        return PreflightOutcome::ready(new ShipmentRequest(
            shipmentId: $shipment->id,
            merchantOrderRef: $shipment->number,
            recipientName: $order->recipientName,
            recipientPhone: $order->phone,
            addressLine: $order->addressLine,
            landmark: $order->landmark,
            districtId: $order->districtId,
            upazilaId: $order->upazilaId,
            courierLocation: $location,
            codAmount: $shipment->cod_amount,
            declaredValue: $shipment->declared_value,
            weightGrams: $shipment->weight_grams,
            itemCount: $shipment->item_count,
            itemDescription: $order->itemDescription,
            note: $shipment->note,
        ));
    }

    public static function isProduction(): bool
    {
        /** @var list<string> $envs */
        $envs = (array) config('couriers.production_environments', ['production']);

        return app()->environment($envs);
    }

    private function location(Shipment $shipment, CourierProvider $provider, ?int $districtId, ?int $upazilaId): CourierLocationRef|PreflightOutcome
    {
        if ($shipment->courier_location_id !== null) {
            $chosen = CourierLocation::query()->mapped()
                ->where('provider_id', $provider->id)
                ->whereKey($shipment->courier_location_id)
                ->first();

            return $chosen?->ref() ?? PreflightOutcome::blocked(
                Shipment::FAILURE_UNMAPPED,
                "The chosen {$provider->name} location is not a mapped location of this provider. Map it before booking.",
            );
        }

        $preview = $this->locations->for($provider, $districtId, $upazilaId);

        return match ($preview['status']) {
            'mapped' => $preview['options']->firstOrFail()->ref(),
            'ambiguous' => PreflightOutcome::blocked(
                Shipment::FAILURE_AMBIGUOUS,
                "Several {$provider->name} zones are mapped to this address. Choose one on the shipment and retry.",
            ),
            default => PreflightOutcome::blocked(
                Shipment::FAILURE_UNMAPPED,
                "This address's area is not mapped to a {$provider->name} zone. Map it under courier locations, then retry.",
            ),
        };
    }
}
