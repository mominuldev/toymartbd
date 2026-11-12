<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use App\Support\Money;

/** What the promotion stage did: the discounts in the order applied, and each line's amount after them. */
final readonly class PromotionOutcome
{
    /**
     * @param  list<AppliedDiscount>  $discounts
     * @param  array<int, Money>  $remaining  variant id → line amount after promotions
     */
    public function __construct(
        public array $discounts,
        public array $remaining,
        /** The non-stackable promotion that ended the chain, if one did. */
        public ?int $stoppedBy = null,
    ) {}
}
