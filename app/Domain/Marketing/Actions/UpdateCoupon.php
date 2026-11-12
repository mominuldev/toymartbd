<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\DTOs\CouponData;
use App\Domain\Marketing\Models\Coupon;
use Illuminate\Support\Facades\DB;

/**
 * Replace a coupon's settings (admin). `used_count` and usages are never touched here.
 * Changing the terms of a coupon already used does not rewrite past orders: their
 * discount is on the order.
 */
final class UpdateCoupon
{
    public function handle(Coupon $coupon, CouponData $data): Coupon
    {
        return DB::transaction(function () use ($coupon, $data): Coupon {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
            $coupon->fill($data->toAttributes());
            $coupon->code = Coupon::normaliseCode($data->code);
            $coupon->save();

            return $coupon->refresh();
        });
    }
}
