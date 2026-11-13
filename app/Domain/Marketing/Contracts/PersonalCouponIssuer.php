<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Contracts;

/**
 * Mint a single-use coupon that only one customer can redeem (P8 batch 3: the offer in a
 * child's birthday reminder). The seam another domain calls instead of writing `coupons`
 * itself (rule 2): Notification depends on this interface only (deptrac layer
 * MarketingContracts), Marketing implements it ({@see \App\Domain\Marketing\Actions\IssuePersonalCoupon},
 * bound in AppServiceProvider).
 *
 * Call it inside the caller's transaction so the coupon exists only if the caller's record of
 * sending it does — a replayed send then mints nothing. It makes no external call.
 */
interface PersonalCouponIssuer
{
    public function issue(int $customerId, PersonalCouponTerms $terms): IssuedCoupon;
}
