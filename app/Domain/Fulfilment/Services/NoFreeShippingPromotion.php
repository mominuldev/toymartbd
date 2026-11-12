<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Contracts\FreeShippingPromotion;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Support\Money;

/** No free-shipping promotions or coupons exist yet (Marketing, Phase 5). */
final class NoFreeShippingPromotion implements FreeShippingPromotion
{
    public function qualifies(DeliveryParcel $parcel, DeliveryZone $zone, Money $subtotal): bool
    {
        return false;
    }
}
