<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Enums;

/** `coupons.type` (DATABASE-DESIGN §3.8). */
enum CouponType: string
{
    /** `value` taka off the eligible goods, never more than they cost. */
    case Fixed = 'fixed';
    /** `value` percent off the eligible goods, capped at `max_discount`. */
    case Percent = 'percent';
    /** Delivery becomes free (DATABASE-DESIGN §5.1 step 1); goods are not discounted. */
    case FreeShipping = 'free_shipping';
}
