<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\Coupon;
use Illuminate\Support\Facades\DB;

/**
 * Deactivate a coupon. Never a hard delete: `coupon_usages` (and so past orders'
 * per-customer limits) reference it. Idempotent.
 */
final class DeactivateCoupon
{
    public function handle(Coupon $coupon): Coupon
    {
        return DB::transaction(function () use ($coupon): Coupon {
            $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);
            if ($coupon->is_active) {
                $coupon->is_active = false;
                $coupon->save();
            }

            return $coupon;
        });
    }
}
