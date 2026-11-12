<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Fulfilment\Contracts\FreeShippingPromotion;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Models\Coupon;
use App\Support\Money;

/**
 * DATABASE-DESIGN §5.1 step 1 for Marketing: a `free_shipping` coupon that is active,
 * in its window and whose minimum the subtotal meets makes delivery free. Bound over
 * Fulfilment's NoFreeShippingPromotion in AppServiceProvider.
 *
 * The parcel carries a coupon code only when the pricing pipeline has already
 * validated the coupon in full (limits, scope) — the PDP estimator never sends one.
 */
final class CouponFreeShipping implements FreeShippingPromotion
{
    public function qualifies(DeliveryParcel $parcel, DeliveryZone $zone, Money $subtotal): bool
    {
        if ($parcel->couponCode === null || $parcel->couponCode === '') {
            return false;
        }

        $coupon = Coupon::findByCode($parcel->couponCode);
        $now = now();

        return $coupon !== null
            && $coupon->type === CouponType::FreeShipping
            && $coupon->is_active
            && ($coupon->starts_at === null || ! $coupon->starts_at->greaterThan($now))
            && ($coupon->ends_at === null || $coupon->ends_at->greaterThan($now))
            && ! $subtotal->lessThan($coupon->min_order_amount);
    }
}
