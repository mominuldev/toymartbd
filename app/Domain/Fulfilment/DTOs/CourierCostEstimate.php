<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Support\Money;

/**
 * What a parcel will cost us with one courier, from that courier's STORED rate card — not a
 * provider quote (04-admin-spec A16 "Charge preview"). `costIfRefused` is the delivery charge
 * plus the return charge: a refused COD parcel earns nothing.
 */
final readonly class CourierCostEstimate
{
    public function __construct(
        public DeliveryZone $zone,
        public int $weightGrams,
        public Money $baseCharge,
        public Money $weightSurcharge,
        public Money $deliveryCharge,
        public string $codFeePercent,
        public Money $codFee,
        public Money $returnCharge,
        public Money $totalIfDelivered,
        public Money $costIfRefused,
    ) {}
}
