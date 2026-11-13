<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use App\Domain\Marketing\Models\Promotion;
use App\Support\Money;

/**
 * What the promotion stage did: the discounts in the order applied, each line's amount
 * after them, the lines a coupon may not touch, and the free-delivery promotions the
 * chain reached (evaluated at the delivery stage, after the coupon).
 */
final readonly class PromotionOutcome
{
    /**
     * @param  list<AppliedDiscount>  $discounts
     * @param  array<int, Money>  $remaining  variant id → line amount after promotions
     * @param  list<Promotion>  $freeDelivery  free-delivery candidates, in priority order
     */
    public function __construct(
        public array $discounts,
        public array $remaining,
        /** The non-stackable promotion that ended the chain, if one did. */
        public ?int $stoppedBy = null,
        public array $freeDelivery = [],
    ) {}

    /** @return list<int> variant ids a coupon may not discount */
    public function couponBlockedVariantIds(): array
    {
        $ids = [];
        foreach ($this->discounts as $discount) {
            if (! $discount->allowsCoupons) {
                array_push($ids, ...array_keys($discount->perLine));
            }
        }

        return array_values(array_unique($ids));
    }
}
