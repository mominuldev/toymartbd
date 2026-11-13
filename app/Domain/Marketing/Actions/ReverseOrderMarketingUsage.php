<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Services\CouponUsages;
use App\Domain\Marketing\Services\PromotionUsages;
use Illuminate\Support\Facades\DB;

/**
 * A cancelled order gives back what it took from Marketing (OrderCancelled's contract):
 * its coupon usage (`used_count--`) and its promotion usages — usage counts, budget spent
 * and flash-sale units, so the allotment can be sold again. Idempotent: a second run
 * finds no usage rows. Coupon first, then promotions (ascending id), as at placement.
 */
final readonly class ReverseOrderMarketingUsage
{
    public function __construct(
        private CouponUsages $coupons,
        private PromotionUsages $promotions,
    ) {}

    public function handle(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $this->coupons->reverseForOrder($orderId);
            $this->promotions->reverseForOrder($orderId);
        });
    }
}
