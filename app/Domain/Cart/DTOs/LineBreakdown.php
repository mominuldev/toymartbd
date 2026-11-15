<?php

declare(strict_types=1);

namespace App\Domain\Cart\DTOs;

use App\Domain\Catalog\Models\ProductVariant;
use App\Support\Money;

/**
 * One priced line: `subtotal - promotionDiscount - couponDiscount - manualDiscount = total`.
 * `discount()` is what an order item stores as its discount. `manualDiscount` is the line's
 * share of a cashier's POS discount ({@see PriceBreakdown::withManualDiscount()}); zero
 * everywhere else.
 */
final readonly class LineBreakdown
{
    public Money $manualDiscount;

    public function __construct(
        public ProductVariant $variant,
        public int $quantity,
        public Money $unitPrice,
        public Money $subtotal,
        public Money $promotionDiscount,
        public Money $couponDiscount,
        public Money $total,
        ?Money $manualDiscount = null,
    ) {
        $this->manualDiscount = $manualDiscount ?? Money::zero();
    }

    public function discount(): Money
    {
        return $this->promotionDiscount->plus($this->couponDiscount)->plus($this->manualDiscount);
    }
}
