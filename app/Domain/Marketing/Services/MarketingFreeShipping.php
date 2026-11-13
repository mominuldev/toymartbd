<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Fulfilment\Contracts\FreeShippingPromotion;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Marketing\Enums\PromotionType;
use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;

/**
 * DATABASE-DESIGN §5.1 step 1 for Marketing (bound over NoFreeShippingPromotion in
 * AppServiceProvider): delivery is free when
 *
 *  - the parcel carries a free-shipping coupon that is still valid (CouponFreeShipping), or
 *  - the parcel carries a free-delivery promotion the pipeline chose, still running, whose
 *    threshold the post-discount subtotal meets, or
 *  - (PDP estimator — the parcel has no priced subtotal) a running all-scope free-delivery promotion with no
 *    per-customer limit applies to this subtotal.
 */
final readonly class MarketingFreeShipping implements FreeShippingPromotion
{
    public function __construct(
        private CouponFreeShipping $coupons,
        private FreeDeliveryPromotions $promotions,
    ) {}

    public function qualifies(DeliveryParcel $parcel, DeliveryZone $zone, Money $subtotal): bool
    {
        if ($this->coupons->qualifies($parcel, $zone, $subtotal)) {
            return true;
        }

        if ($parcel->promotionId !== null) {
            $promotion = Promotion::query()->runningAt(now())->whereKey($parcel->promotionId)->first();

            return $promotion !== null
                && $promotion->type === PromotionType::FreeDelivery
                && ! $subtotal->lessThan(FreeDeliveryPromotions::threshold($promotion));
        }

        if ($parcel->subtotal === null) {
            return $this->promotions->forAnyone($subtotal, now()) !== null;
        }

        return false;
    }
}
