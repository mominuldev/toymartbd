<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\DTOs\CourierCostEstimate;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Support\Money;

/**
 * The booking panel's charge preview (P6, 04-admin-spec A16): what a parcel costs US with a
 * courier, from its stored rate card. Read-only, no transaction, no external call (rule 4).
 * The weight step is the resolver's own ({@see DeliveryChargeResolver::weightSurcharge()}) so
 * there is one implementation of "per started kg above the included weight" (rule 32a: each
 * provider's allowance is its own column — Steadfast 1 kg, Pathao 2 kg).
 *
 * The COD fee is `cod_fee_percent` of the COD amount to the poisha; how each courier rounds
 * it is not verified without a merchant account (rule 33a), so the screen calls it an estimate.
 */
final readonly class CourierCostEstimator
{
    public function __construct(private DeliveryChargeResolver $resolver) {}

    public function estimate(CourierProvider $provider, DeliveryZone $zone, int $weightGrams, Money $codAmount): CourierCostEstimate
    {
        $base = $provider->baseChargeFor($zone);
        $surcharge = $this->resolver->weightSurcharge($provider, $weightGrams);
        $delivery = $base->plus($surcharge);
        $percent = number_format((float) $provider->cod_fee_percent, 2, '.', '');
        $codFee = $codAmount->isZero() ? Money::zero() : $codAmount->percentage($percent);

        return new CourierCostEstimate(
            zone: $zone,
            weightGrams: $weightGrams,
            baseCharge: $base,
            weightSurcharge: $surcharge,
            deliveryCharge: $delivery,
            codFeePercent: $percent,
            codFee: $codFee,
            returnCharge: $provider->return_charge,
            totalIfDelivered: $delivery->plus($codFee),
            costIfRefused: $delivery->plus($provider->return_charge),
        );
    }
}
