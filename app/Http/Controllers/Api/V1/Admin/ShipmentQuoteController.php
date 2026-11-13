<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Contracts\ShippableOrders;
use App\Domain\Fulfilment\DTOs\CourierCostEstimate;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\BookingLocationPreview;
use App\Domain\Fulfilment\Services\CourierCostEstimator;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Domain\Fulfilment\Support\CourierCredentialFields;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Shipment\ShipmentQuoteRequest;
use App\Http\Resources\Admin\ShipmentQuoteResource;
use App\Support\Money;

/**
 * `POST /shipments/quote` (P6) — everything the booking panel shows before "Book": per
 * courier its trust state and whether it can take a booking at all; per order the parcel
 * prefilled from the server (weight from variant weights, item count, COD = what is still
 * due, declared value), the courier area mapping (rule 32) and the cost estimate from the
 * stored rate card. The browser computes none of it (rule 1). Read-only.
 */
final class ShipmentQuoteController extends ApiController
{
    public function __invoke(
        ShipmentQuoteRequest $request,
        ShippableOrders $orders,
        DeliveryChargeResolver $zones,
        CourierCostEstimator $estimator,
        BookingLocationPreview $locations,
    ): ShipmentQuoteResource {
        $providers = CourierProvider::query()
            ->addSelect(['bookings_count' => Shipment::query()->selectRaw('count(*)')->whereColumn('provider_id', 'courier_providers.id')->whereNotNull('consignment_id')])
            ->orderBy('position')->orderBy('id')->get();
        $production = in_array(app()->environment(), (array) config('couriers.production_environments', ['production']), true);

        $providerRows = $providers->map(static function (CourierProvider $p) use ($production): array {
            $complete = CourierCredentialFields::complete($p);
            $blocked = match (true) {
                ! $p->is_active => 'inactive',
                ! $complete => 'not_connected',
                $production && ! $p->verified => 'unverified_in_production',
                default => null,
            };

            return [
                'provider' => $p,
                'trust' => ! $complete ? 'not_connected' : ($p->verified ? 'verified' : 'unverified'),
                'bookings_count' => (int) $p->getAttribute('bookings_count'),
                'blocked_reason' => $blocked,
            ];
        })->all();

        $orderRows = [];
        $totals = [];
        foreach ($request->weights() as $orderId => $override) {
            $order = $orders->find($orderId);
            if ($order === null) {
                $orderRows[] = ['order_id' => $orderId, 'found' => false];

                continue;
            }
            $district = $order->districtId !== null ? District::query()->find($order->districtId) : null;
            $upazila = $order->upazilaId !== null ? Upazila::query()->find($order->upazilaId) : null;
            $zone = $district !== null ? $zones->zoneFor($district, $upazila) : null;
            $weight = $override ?? $order->weightGrams;

            $quotes = [];
            foreach ($providers as $provider) {
                $location = $locations->for($provider, $order->districtId, $order->upazilaId);
                $estimate = $zone !== null ? $estimator->estimate($provider, $zone, $weight, $order->codAmount) : null;
                $quotes[] = [
                    'provider_id' => $provider->id,
                    'location_status' => $location['status'],
                    'location_options' => $location['options']->map(static fn (CourierLocation $l): array => [
                        'id' => $l->id,
                        'city' => $l->external_city_name,
                        'zone' => $l->external_zone_name,
                        'area' => $l->external_area_name,
                        'mapped_at' => $l->mapped_at?->toIso8601String(),
                    ])->values()->all(),
                    'blocked_reason' => match (true) {
                        $district === null => 'missing_address',
                        $zone === null => 'not_serviced',
                        $location['status'] === 'unmapped' => 'unmapped_location',
                        default => null,
                    },
                    'estimate' => $estimate,
                ];
                if ($estimate !== null) {
                    $totals[$provider->id] = self::addTo($totals[$provider->id] ?? null, $estimate, $order->codAmount);
                }
            }

            $orderRows[] = [
                'order_id' => $order->id,
                'found' => true,
                'number' => $order->number,
                'status' => $order->status,
                'can_ship' => $order->canShip,
                'recipient_name' => $order->recipientName,
                'district' => $district,
                'upazila' => $upazila,
                'zone' => $zone?->value,
                'item_count' => $order->itemCount,
                'variant_weight_grams' => $order->weightGrams,
                'weight_grams' => $weight,
                'cod_amount' => $order->codAmount,
                'declared_value' => $order->declaredValue,
                'quotes' => $quotes,
            ];
        }

        return new ShipmentQuoteResource(['providers' => $providerRows, 'orders' => $orderRows, 'totals' => $totals]);
    }

    /**
     * @param  array{parcels: int, weight_grams: int, cod_amount: Money, total_if_delivered: Money}|null  $sum
     * @return array{parcels: int, weight_grams: int, cod_amount: Money, total_if_delivered: Money}
     */
    private static function addTo(?array $sum, CourierCostEstimate $e, Money $cod): array
    {
        $sum ??= ['parcels' => 0, 'weight_grams' => 0, 'cod_amount' => Money::zero(), 'total_if_delivered' => Money::zero()];

        return [
            'parcels' => $sum['parcels'] + 1,
            'weight_grams' => $sum['weight_grams'] + $e->weightGrams,
            'cod_amount' => $sum['cod_amount']->plus($cod),
            'total_if_delivered' => $sum['total_if_delivered']->plus($e->totalIfDelivered),
        ];
    }
}
