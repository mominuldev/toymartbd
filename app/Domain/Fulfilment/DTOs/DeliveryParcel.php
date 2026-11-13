<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Support\Money;

/**
 * What is being delivered: variant id → quantity. The resolver prices and weighs the
 * lines from current variant data. `subtotal` overrides the priced subtotal — checkout
 * passes its re-priced, post-discount figure (DATABASE-DESIGN §4.1); the PDP leaves it
 * null. `couponCode` and `promotionId` are carried for the free-shipping seam only:
 * the pricing pipeline sets them after validating the coupon / free-delivery promotion.
 */
final readonly class DeliveryParcel
{
    /** @param  array<int, int>  $lines */
    public function __construct(
        public array $lines,
        public ?Money $subtotal = null,
        public ?string $couponCode = null,
        public ?int $promotionId = null,
    ) {}
}
