<?php

declare(strict_types=1);

namespace App\Domain\Cart\DTOs;

use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money;

/**
 * One priced line: `subtotal - promotionDiscount - couponDiscount = total`.
 * `discount()` is what an order item stores as its discount.
 */
final readonly class LineBreakdown
{
    public function __construct(
        public ProductVariant $variant,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        public Money $promotionDiscount,
        public Money $couponDiscount,
        public Money $total,
    ) {}

    public function discount(): Money
    {
        return $this->promotionDiscount->plus($this->couponDiscount);
    }
}
