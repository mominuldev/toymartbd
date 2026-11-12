<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Enums;

use App\Support\Money;

/**
 * Why a coupon does not apply — a stable machine code (`coupon.reason` in the cart
 * API) plus a localised message (lang/{en,bn}/cart.php).
 */
enum CouponRejection: string
{
    case NotFound = 'not_found';
    case Inactive = 'inactive';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case MinimumNotMet = 'minimum_not_met';
    case UsageLimitReached = 'usage_limit_reached';
    case CustomerLimitReached = 'customer_limit_reached';
    case NotApplicable = 'not_applicable';
    case FirstOrderOnly = 'first_order_only';

    public function message(?Money $minimum = null): string
    {
        return (string) __('cart.coupon.'.$this->value, ['minimum' => $minimum?->toDecimal() ?? '']);
    }
}
