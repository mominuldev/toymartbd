<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Support\Money;

/**
 * Reads an aggregate from the database as Money (rule 6).
 *
 * MySQL returns SUM(DECIMAL(14,2)) as an exact decimal string. SQLite (the test driver)
 * has no decimal type and may return a REAL such as 1199.9999999998; that is rounded to
 * the poisha here, once, so report arithmetic downstream stays bcmath on strings.
 */
final class ReportMoney
{
    public static function of(mixed $value): Money
    {
        if ($value === null || $value === '') {
            return Money::zero();
        }
        if (is_float($value)) {
            return Money::of(sprintf('%.2F', round($value, 2)));
        }
        if (is_int($value)) {
            return Money::of($value);
        }

        $text = is_scalar($value) ? (string) $value : '0';
        // Already two decimals (what MySQL returns for a DECIMAL(14,2) column): nothing to round.
        if (preg_match('/^-?\d+\.\d\d$/', $text) === 1) {
            return Money::of($text);
        }
        // A 4-dp column (cost_price_snapshot, avg_cost) or a SQLite string with more digits.
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return Money::of(sprintf('%.2F', round((float) $text, 2)));
        }

        return Money::of(self::round($text));
    }

    /** Percent `part / whole × 100` to 2 dp, or null when `whole` is zero. */
    public static function percent(Money $part, Money $whole): ?string
    {
        if ($whole->isZero()) {
            return null;
        }

        return self::round(bcdiv(bcmul($part->toDecimal(), '100', 8), $whole->toDecimal(), 8));
    }

    /** Change from `before` to `now` as a percent of |before|, or null when `before` is zero. */
    public static function delta(Money $now, Money $before): ?string
    {
        if ($before->isZero()) {
            return null;
        }
        $abs = ltrim($before->toDecimal(), '-');

        return self::round(bcdiv(bcmul($now->minus($before)->toDecimal(), '100', 8), $abs, 8));
    }

    /** Half away from zero to 2 dp, on a decimal string. */
    public static function round(string $value): string
    {
        $half = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $half, 8), '0', 2);
    }
}
