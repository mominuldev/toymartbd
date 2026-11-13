<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Enums\CouponRejection;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CouponUsage;
use App\Support\Money;
use App\Support\PhoneNumber;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Writes and reverses coupon redemptions (DATABASE-DESIGN §4.1 "INSERT coupon_usages,
 * increment coupons.used_count"; §4.4 cancel reverses it).
 *
 * Both methods MUST run inside the caller's transaction (PlaceOrder / CancelOrder own
 * the boundary, rule 3) and refuse to run outside one. record() locks the coupon row
 * FOR UPDATE and re-checks the total and per-phone limits under that lock, so two
 * concurrent checkouts on one phone cannot both use a one-per-customer coupon.
 */
final class CouponUsages
{
    /**
     * @throws CouponRejected when a limit has been reached since the cart was priced
     */
    public function record(Coupon $coupon, int $orderId, PhoneNumber $phone, Money $discount, ?int $customerId = null, ?DateTimeInterface $at = null): CouponUsage
    {
        self::assertInTransaction();

        $coupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

        if ($coupon->redeemableBy($phone, $customerId) !== true) {
            throw new CouponRejected(CouponRejection::NotForCustomer);
        }
        if ($coupon->usage_limit_total !== null && $coupon->used_count >= $coupon->usage_limit_total) {
            throw new CouponRejected(CouponRejection::UsageLimitReached);
        }
        if ($coupon->usage_limit_per_customer !== null && self::countFor($coupon, $phone, $customerId) >= $coupon->usage_limit_per_customer) {
            throw new CouponRejected(CouponRejection::CustomerLimitReached);
        }

        $usage = CouponUsage::query()->create([
            'coupon_id' => $coupon->id,
            'order_id' => $orderId,
            'customer_id' => $customerId,
            'phone' => $phone->e164(),
            'discount_amount' => $discount,
            'used_at' => $at ?? now(),
        ]);

        $coupon->increment('used_count');

        return $usage;
    }

    /** Reverses every usage of an order (cancellation, P4-ORD-09). Returns how many were reversed. */
    public function reverseForOrder(int $orderId): int
    {
        self::assertInTransaction();

        $usages = CouponUsage::query()->where('order_id', $orderId)->orderBy('coupon_id')->get();
        foreach ($usages as $usage) {
            $coupon = Coupon::query()->lockForUpdate()->find($usage->coupon_id);
            if ($coupon !== null && $coupon->used_count > 0) {
                $coupon->decrement('used_count');
            }
            $usage->delete();
        }

        return $usages->count();
    }

    /** Usages by this phone (E.164) or this customer. */
    public static function countFor(Coupon $coupon, ?PhoneNumber $phone, ?int $customerId): int
    {
        if ($phone === null && $customerId === null) {
            return 0;
        }

        return CouponUsage::query()
            ->where('coupon_id', $coupon->id)
            ->where(function ($q) use ($phone, $customerId): void {
                if ($phone !== null) {
                    $q->orWhere('phone', $phone->e164());
                }
                if ($customerId !== null) {
                    $q->orWhere('customer_id', $customerId);
                }
            })
            ->count();
    }

    private static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Coupon usage must be written inside the order transaction.');
        }
    }
}
