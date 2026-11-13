<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

/**
 * Rounding for decimal strings (half away from zero), shared by the receipt calculator
 * and the purchase totals. bcmath only (rule 6).
 *
 * @internal
 */
final class Decimals
{
    public static function round(string $value, int $scale): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);
        if (str_starts_with($value, '-')) {
            $half = '-'.$half;
        }

        return bcadd(bcadd($value, $half, $scale + 6), '0', $scale);
    }
}
