<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Coupon;

use App\Domain\Marketing\Models\Coupon;

/** `PUT /coupons/{coupon}` — the whole document; needs marketing.manage. */
final class UpdateCouponRequest extends CouponRequest
{
    public function authorize(): bool
    {
        $coupon = $this->coupon();

        return $coupon !== null && ($this->user()?->can('update', $coupon) ?? false);
    }

    protected function coupon(): ?Coupon
    {
        $coupon = $this->route('coupon');

        return $coupon instanceof Coupon ? $coupon : null;
    }
}
