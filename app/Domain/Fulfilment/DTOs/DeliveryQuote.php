<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Support\Money;

/**
 * The resolved delivery charge for one destination and parcel — the figure the PDP
 * shows and the one checkout writes to the order (§5.1: same code path).
 *
 * `serviceable = false` means we do not deliver to that upazila (A12.5): zone, charge
 * and ETA are null. It never blocks adding to cart.
 */
final readonly class DeliveryQuote
{
    public function __construct(
        public bool $serviceable,
        public District $district,
        public ?Upazila $upazila,
        public bool $upazilaRequired,
        public ?DeliveryZone $zone,
        public ?CourierProvider $courier,
        public Money $subtotal,
        public int $weightGrams,
        public ?Money $baseCharge,
        public ?Money $weightSurcharge,
        public ?Money $charge,
        public bool $freeShipping,
        /** 'threshold' | 'promotion' | null */
        public ?string $freeShippingReason,
        public ?Money $freeShippingThreshold,
        public ?Money $freeShippingRemaining,
        public ?DeliveryEta $eta,
        public ?Money $codAdvance,
    ) {}
}
