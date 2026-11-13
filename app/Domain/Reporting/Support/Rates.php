<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Finance\Reports\ReportMoney;
use App\Support\Money;

/**
 * Ratios every Reporting query shares, computed on the server (rule 1) with bcmath on
 * strings, rounded half away from zero to 2 dp — the same rounding as the finance reports
 * ({@see ReportMoney}). Nothing to divide by gives null, never 0 or a division error.
 */
final class Rates
{
    /** `part / whole × 100` to 2 dp, or null when `whole` is 0. */
    public static function percent(int $part, int $whole): ?string
    {
        if ($whole === 0) {
            return null;
        }

        return ReportMoney::round(bcdiv(bcmul((string) $part, '100', 8), (string) $whole, 8));
    }

    /** Money `part / whole × 100` to 2 dp, or null when `whole` is zero. */
    public static function moneyPercent(Money $part, Money $whole): ?string
    {
        return ReportMoney::percent($part, $whole);
    }

    /** `total / count` to the poisha, or null when `count` is 0. */
    public static function average(Money $total, int $count): ?Money
    {
        if ($count === 0) {
            return null;
        }

        return Money::of(ReportMoney::round(bcdiv($total->toDecimal(), (string) $count, 8)));
    }

    /** Change from `before` to `now` as a percent of |before|, or null when `before` is 0. */
    public static function countDelta(int $now, int $before): ?string
    {
        if ($before === 0) {
            return null;
        }

        return ReportMoney::round(bcdiv(bcmul((string) ($now - $before), '100', 8), (string) abs($before), 8));
    }

    public static function moneyDelta(Money $now, Money $before): ?string
    {
        return ReportMoney::delta($now, $before);
    }

    /** Difference between two percentages in percentage points ("0.03"), or null if either is null. */
    public static function points(?string $now, ?string $before): ?string
    {
        if ($now === null || $before === null) {
            return null;
        }

        return bcsub($now, $before, 2);
    }
}
