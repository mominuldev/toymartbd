<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Services\NoFreeShippingPromotion;
use App\Support\Money;
use Illuminate\Container\Attributes\Bind;

/**
 * §5.1 step 1 — "free-shipping promotion or coupon active and order qualifies → 0".
 * The seam for Marketing's free-shipping coupons and promotions (P5, M-01), which do
 * not exist yet: until they do, {@see NoFreeShippingPromotion} answers no. Marketing
 * will bind its own implementation; the resolver does not change.
 */
#[Bind(NoFreeShippingPromotion::class)]
interface FreeShippingPromotion
{
    public function qualifies(DeliveryParcel $parcel, DeliveryZone $zone, Money $subtotal): bool;
}
