<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\DTOs\CouponData;
use App\Domain\Marketing\Models\Coupon;
use Illuminate\Support\Facades\DB;

/** Create a coupon (admin, P4-CRT-04). The code is stored upper-case. */
final class CreateCoupon
{
    public function handle(CouponData $data, ?int $createdBy = null): Coupon
    {
        return DB::transaction(function () use ($data, $createdBy): Coupon {
            $coupon = new Coupon($data->toAttributes());
            $coupon->code = Coupon::normaliseCode($data->code);
            $coupon->created_by = $createdBy;
            $coupon->save();

            return $coupon->refresh();
        });
    }
}
