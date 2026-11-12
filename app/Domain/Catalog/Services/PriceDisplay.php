<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Support\Money;

/**
 * The derived numbers beside a price on the storefront — computed here, never in a
 * client (rule 1). A compare-at price counts only when it is higher than the price.
 */
final class PriceDisplay
{
    /** The was-price to show, or null when there is none or it is not higher. */
    public static function compareAt(Money $price, ?Money $compareAt): ?Money
    {
        return $compareAt !== null && $compareAt->greaterThan($price) ? $compareAt : null;
    }

    /** Whole percent off the was-price, rounded down ("−15%"): never overstates a saving. */
    public static function discountPercent(Money $price, Money $compareAt): int
    {
        return (int) bcdiv(bcmul(bcsub($compareAt->amount, $price->amount, 2), '100', 2), $compareAt->amount, 0);
    }

    /** The saving in taka ("You save ৳740"). */
    public static function saving(Money $price, Money $compareAt): Money
    {
        return $compareAt->minus($price);
    }
}
