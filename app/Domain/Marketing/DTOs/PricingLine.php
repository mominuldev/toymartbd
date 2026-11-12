<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use App\Support\Money;

/**
 * One cart/order line as promotions and coupons see it: what it is (for scope
 * matching) and its current base price. `categoryIds` are every category the product
 * is filed under (not only the primary one).
 */
final readonly class PricingLine
{
    /** @param  list<int>  $categoryIds */
    public function __construct(
        public int $variantId,
        public int $productId,
        public ?int $brandId,
        public array $categoryIds,
        public Money $unitPrice,
        public int $quantity,
    ) {}

    public function subtotal(): Money
    {
        return $this->unitPrice->times($this->quantity);
    }
}
