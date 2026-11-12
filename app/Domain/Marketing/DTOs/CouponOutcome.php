<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use App\Domain\Marketing\Enums\CouponRejection;
use App\Domain\Marketing\Models\Coupon;

/**
 * The coupon stage's answer: applied (`discount` set) or rejected (`rejection` set).
 * `coupon` is null only for an unknown code.
 */
final readonly class CouponOutcome
{
    public function __construct(
        public string $code,
        public ?Coupon $coupon,
        public ?AppliedDiscount $discount,
        public ?CouponRejection $rejection,
    ) {}

    public function applied(): bool
    {
        return $this->discount !== null;
    }

    public function message(): ?string
    {
        return $this->rejection?->message($this->coupon?->min_order_amount);
    }
}
