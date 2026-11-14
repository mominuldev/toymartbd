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
 *
 * Runs synchronously after the cancel commits, against EditOrder's documented tier-4
 * residue (DATABASE-DESIGN §4.8): a deadlock victim is retried here rather than surfacing
 * as a 500 on a cancel that already committed (P9, LOAD-AND-CONCURRENCY F-4).
 */
final readonly class ReverseOrderMarketingUsage
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private CouponUsages $coupons,
        private PromotionUsages $promotions,
    ) {}

    public function handle(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $this->coupons->reverseForOrder($orderId);
            $this->promotions->reverseForOrder($orderId);
        }, self::ATTEMPTS);
    }
}
