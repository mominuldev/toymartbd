<?php

declare(strict_types=1);

namespace App\Domain\Cart\DTOs;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Support\PhoneNumber;
use DateTimeInterface;

/**
 * Everything the pricing pipeline needs. The cart and checkout (PlaceOrder) build the
 * same input, so the cart's total and the order's total come from one code path.
 *
 * - `lines`: variant id → quantity.
 * - `district`/`upazila`: the destination; without a district there is no delivery
 *   line and `grandTotal` excludes delivery.
 * - `phone` (E.164 key) / `customerId`: enable the per-customer and first-order coupon
 *   checks. Checkout must pass the phone.
 */
final readonly class PricingInput
{
    /** @param  array<int, int>  $lines */
    public function __construct(
        public array $lines,
        public ?string $couponCode = null,
        public ?District $district = null,
        public ?Upazila $upazila = null,
        public ?PhoneNumber $phone = null,
        public ?int $customerId = null,
        public ?DateTimeInterface $now = null,
    ) {}
}
