<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Domain\Marketing\Contracts\CustomerOrderHistory;
use App\Domain\Marketing\DTOs\AppliedDiscount;
use App\Domain\Marketing\DTOs\CouponOutcome;
use App\Domain\Marketing\DTOs\PricingLine;
use App\Domain\Marketing\Enums\CouponRejection;
use App\Domain\Marketing\Enums\CouponType;
use App\Domain\Marketing\Models\Coupon;
use App\Support\Money;
use App\Support\PhoneNumber;
use DateTimeInterface;

/**
 * The coupon stage of the pricing pipeline (P4-CRT-04). Checks, in order: exists,
 * active, window, total limit, per-customer limit (keyed on the E.164 phone, plus the
 * customer id when known — so it holds across guest checkouts on one phone), first
 * order only, scope/exclusions, minimum order. Then computes the discount on the
 * eligible lines' post-promotion amounts.
 *
 * Without a phone (a cart before checkout) the per-customer and first-order checks
 * cannot be made and are skipped; checkout always passes the phone, and
 * CouponUsages::record() re-checks the limits under a row lock.
 *
 * Read-only; no transaction.
 */
final readonly class CouponEvaluator
{
    public function __construct(private CustomerOrderHistory $history) {}

    /**
     * @param  list<PricingLine>  $lines
     * @param  array<int, Money>  $remaining  variant id → line amount after promotions
     * @param  list<int>  $blockedVariantIds  lines a promotion with `allows_coupons = false` discounted (P7)
     */
    public function evaluate(
        string $code,
        array $lines,
        array $remaining,
        DateTimeInterface $now,
        ?PhoneNumber $phone = null,
        ?int $customerId = null,
        array $blockedVariantIds = [],
    ): CouponOutcome {
        $coupon = Coupon::findByCode($code);
        $code = Coupon::normaliseCode($code);

        if ($coupon === null) {
            return new CouponOutcome($code, null, null, CouponRejection::NotFound);
        }

        $rejection = $this->eligibility($coupon, $now, $phone, $customerId);
        if ($rejection !== null) {
            return new CouponOutcome($coupon->code, $coupon, null, $rejection);
        }

        $scope = ProductScope::make($coupon->applies_to, $coupon->scopeIds(), $coupon->excludes);
        $bases = [];
        $blocked = false;
        foreach ($scope->filter($lines) as $line) {
            if (in_array($line->variantId, $blockedVariantIds, true)) {
                $blocked = true;

                continue;
            }
            $bases[$line->variantId] = $remaining[$line->variantId] ?? Money::zero();
        }
        if ($bases === []) {
            return new CouponOutcome($coupon->code, $coupon, null, $blocked ? CouponRejection::NotCombinable : CouponRejection::NotApplicable);
        }

        if (Allocator::sum($remaining)->lessThan($coupon->min_order_amount)) {
            return new CouponOutcome($coupon->code, $coupon, null, CouponRejection::MinimumNotMet);
        }

        $eligible = Allocator::sum($bases);
        $amount = match ($coupon->type) {
            CouponType::Fixed => $coupon->value->lessThan($eligible) ? $coupon->value : $eligible,
            CouponType::Percent => $eligible->percentage($coupon->value->amount),
            CouponType::FreeShipping => Money::zero(),
        };
        if ($coupon->max_discount !== null && $amount->greaterThan($coupon->max_discount)) {
            $amount = $coupon->max_discount;
        }

        $perLine = Allocator::spread($amount, $bases);

        return new CouponOutcome($coupon->code, $coupon, new AppliedDiscount(
            AppliedDiscount::COUPON, $coupon->id, $coupon->code, null, Allocator::sum($perLine), $perLine,
            freeShipping: $coupon->type === CouponType::FreeShipping,
        ), null);
    }

    /** Status, window and limits — everything that does not depend on the cart's contents. */
    public function eligibility(Coupon $coupon, DateTimeInterface $now, ?PhoneNumber $phone = null, ?int $customerId = null): ?CouponRejection
    {
        return match (true) {
            ! $coupon->is_active => CouponRejection::Inactive,
            $coupon->starts_at !== null && $coupon->starts_at->greaterThan($now) => CouponRejection::NotStarted,
            $coupon->ends_at !== null && ! $coupon->ends_at->greaterThan($now) => CouponRejection::Expired,
            $coupon->usage_limit_total !== null && $coupon->used_count >= $coupon->usage_limit_total => CouponRejection::UsageLimitReached,
            $coupon->usage_limit_per_customer !== null && ($phone !== null || $customerId !== null)
                && CouponUsages::countFor($coupon, $phone, $customerId) >= $coupon->usage_limit_per_customer => CouponRejection::CustomerLimitReached,
            $coupon->first_order_only && $phone !== null && $this->history->hasOrdered($phone) => CouponRejection::FirstOrderOnly,
            default => null,
        };
    }
}
