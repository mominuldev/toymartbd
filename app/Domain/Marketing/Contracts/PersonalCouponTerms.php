<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Contracts;

use App\Support\Money;
use InvalidArgumentException;

/**
 * The terms of a personal coupon ({@see PersonalCouponIssuer}). `type` is `percent` (value =
 * percent off, capped at `maxDiscount` when set) or `fixed` (value = taka off). Valid from
 * issue for `validDays` whole days, to the end of the last Dhaka day. `prefix` starts the code
 * (`BDAY-7KQ2MXRT`); `batch` groups the codes on the admin coupon list.
 */
final readonly class PersonalCouponTerms
{
    public function __construct(
        public string $type,
        public Money $value,
        public ?Money $maxDiscount,
        public Money $minOrderAmount,
        public int $validDays,
        public string $prefix,
        public string $batch,
        public string $description,
    ) {
        if (! in_array($type, ['percent', 'fixed'], true)) {
            throw new InvalidArgumentException("A personal coupon is percent or fixed, not [{$type}].");
        }
        if ($validDays < 1) {
            throw new InvalidArgumentException('A personal coupon is valid for at least one day.');
        }
    }
}
