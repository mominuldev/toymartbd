<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Contracts\FreeShippingPromotion;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\DTOs\DeliveryQuote;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Exceptions\DeliveryQuoteUnavailable;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Support\Money;
use DateTimeInterface;

/**
 * THE delivery-charge implementation (DATABASE-DESIGN §5.1, P4-CRT-03). The storefront
 * estimator, the cart and checkout all call {@see self::quote()}; checkout writes the
 * resolved charge to the order and never recomputes it. A second implementation
 * anywhere — including the browser — is a bug (rule 1).
 *
 * Order of resolution:
 *   1. a free-shipping promotion/coupon qualifies ({@see FreeShippingPromotion}) → 0
 *   2. zone from district/upazila → inside_dhaka_metro | dhaka_suburb | outside_dhaka
 *      (or not serviced)
 *   3. base charge for the zone from the default courier provider's rate card
 *   4. + per_kg_charge for each started kg above the provider's included weight,
 *      using Σ variant.weight_grams × quantity
 *   5. subtotal ≥ the store's free-shipping threshold → 0
 *
 * Read-only: no transaction, no writes, no external call (a live courier quote, if
 * ever added, must happen outside any transaction — rule 4).
 */
final readonly class DeliveryChargeResolver
{
    public const int GRAMS_PER_KG = 1000;

    public function __construct(
        private DeliverySettings $settings,
        private DeliveryEtaCalculator $eta,
        private FreeShippingPromotion $promotions,
    ) {}

    public function quote(District $district, ?Upazila $upazila, DeliveryParcel $parcel, ?DateTimeInterface $now = null): DeliveryQuote
    {
        [$subtotal, $weight] = $this->weigh($parcel);
        $zone = $this->zoneFor($district, $upazila);
        $threshold = $this->settings->freeShippingThreshold();
        $remaining = $threshold === null || ! $subtotal->lessThan($threshold) ? null : $threshold->minus($subtotal);

        if ($zone === null) {
            return new DeliveryQuote(
                serviceable: false, district: $district, upazila: $upazila, upazilaRequired: $this->requiresUpazila($district),
                zone: null, courier: null, subtotal: $subtotal, weightGrams: $weight,
                baseCharge: null, weightSurcharge: null, charge: null, freeShipping: false, freeShippingReason: null,
                freeShippingThreshold: $threshold, freeShippingRemaining: $remaining, eta: null, codAdvance: null,
            );
        }

        $courier = CourierProvider::defaultForQuotes() ?? throw DeliveryQuoteUnavailable::noProvider();
        $base = $courier->baseChargeFor($zone);
        $surcharge = $this->weightSurcharge($courier, $weight);

        $reason = match (true) {
            $this->promotions->qualifies($parcel, $zone, $subtotal) => 'promotion',
            $threshold !== null && ! $subtotal->lessThan($threshold) => 'threshold',
            default => null,
        };

        [$min, $max] = $this->settings->etaDays($zone);

        return new DeliveryQuote(
            serviceable: true,
            district: $district,
            upazila: $upazila,
            upazilaRequired: $this->requiresUpazila($district),
            zone: $zone,
            courier: $courier,
            subtotal: $subtotal,
            weightGrams: $weight,
            baseCharge: $base,
            weightSurcharge: $surcharge,
            charge: $reason === null ? $base->plus($surcharge) : Money::zero(),
            freeShipping: $reason !== null,
            freeShippingReason: $reason,
            freeShippingThreshold: $threshold,
            freeShippingRemaining: $reason === null ? $remaining : null,
            eta: $this->eta->window($min, $max, $now),
            codAdvance: $this->codAdvance($zone, $subtotal),
        );
    }

    /**
     * Our charge tier for an address; null = not serviced. An upazila must belong to the
     * district (the caller validates that).
     */
    public function zoneFor(District $district, ?Upazila $upazila): ?DeliveryZone
    {
        if ($upazila !== null) {
            if (in_array($upazila->id, $this->settings->notServicedUpazilaIds(), true)) {
                return null;
            }
            if ($upazila->is_inside_dhaka_metro) {
                return DeliveryZone::InsideDhakaMetro;
            }
            if ($upazila->is_dhaka_subcity) {
                return DeliveryZone::DhakaSuburb;
            }
        }

        if (in_array($district->id, $this->settings->suburbDistrictIds(), true)) {
            return DeliveryZone::DhakaSuburb;
        }

        if ($upazila === null && in_array($district->id, $this->settings->metroDistrictIds(), true)) {
            return DeliveryZone::InsideDhakaMetro;
        }

        return DeliveryZone::OutsideDhaka;
    }

    /**
     * Whether the district alone does not settle the charge — some upazila resolves to a
     * different tier or is not serviced — so the estimator must ask for the upazila
     * (A12.2 "second selector").
     */
    public function requiresUpazila(District $district): bool
    {
        $districtZone = $this->zoneFor($district, null);

        $upazilas = $district->relationLoaded('upazilas') ? $district->upazilas : $district->upazilas()->get();

        foreach ($upazilas as $upazila) {
            if ($this->zoneFor($district, $upazila) !== $districtZone) {
                return true;
            }
        }

        return false;
    }

    /**
     * The published rate table (A12.3 fallback caption, the `/delivery-charge` page).
     *
     * @return array{courier: CourierProvider, zones: list<array{zone: DeliveryZone, charge: Money, eta_days: array{int, int}}>, free_shipping_threshold: Money|null, included_weight_grams: int, per_kg_charge: Money, cutoff_time: string}
     */
    public function publishedRates(): array
    {
        $courier = CourierProvider::defaultForQuotes() ?? throw DeliveryQuoteUnavailable::noProvider();

        return [
            'courier' => $courier,
            'zones' => array_map(fn (DeliveryZone $zone): array => [
                'zone' => $zone,
                'charge' => $courier->baseChargeFor($zone),
                'eta_days' => $this->settings->etaDays($zone),
            ], DeliveryZone::cases()),
            'free_shipping_threshold' => $this->settings->freeShippingThreshold(),
            'included_weight_grams' => $courier->included_weight_grams,
            'per_kg_charge' => $courier->per_kg_charge,
            'cutoff_time' => $this->settings->cutoffTime(),
        ];
    }

    /** @return array{Money, int} subtotal at current prices (or the override), total grams */
    private function weigh(DeliveryParcel $parcel): array
    {
        $variants = $parcel->lines === []
            ? collect()
            : ProductVariant::query()->whereIn('id', array_keys($parcel->lines))->get(['id', 'price', 'weight_grams']);

        $subtotal = Money::zero();
        $grams = 0;
        foreach ($variants as $variant) {
            $quantity = $parcel->lines[$variant->id];
            $subtotal = $subtotal->plus($variant->price->times($quantity));
            $grams += $variant->weight_grams * $quantity;
        }

        return [$parcel->subtotal ?? $subtotal, $grams];
    }

    private function weightSurcharge(CourierProvider $courier, int $grams): Money
    {
        $over = $grams - $courier->included_weight_grams;
        if ($over <= 0) {
            return Money::zero();
        }

        return $courier->per_kg_charge->times(intdiv($over + self::GRAMS_PER_KG - 1, self::GRAMS_PER_KG));
    }

    private function codAdvance(DeliveryZone $zone, Money $subtotal): ?Money
    {
        $rule = $this->settings->codAdvance();

        return $rule !== null && in_array($zone->value, $rule['zones'], true) && $subtotal->greaterThan($rule['threshold'])
            ? $rule['amount']
            : null;
    }
}
