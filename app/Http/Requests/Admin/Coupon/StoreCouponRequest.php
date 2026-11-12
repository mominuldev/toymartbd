<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Coupon;

use App\Domain\Marketing\Models\Coupon;

/** `POST /coupons` — needs marketing.manage. */
final class StoreCouponRequest extends CouponRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Coupon::class) ?? false;
    }

    protected function coupon(): ?Coupon
    {
        return null;
    }
}
