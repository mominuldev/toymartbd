<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\DTOs\MarginData;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Per-unit gross margin for the admin product form (rule 1: the server computes it).
 *
 *   net     = price ÷ (1 + vat_rate / 100)       — selling prices include VAT
 *   profit  = net − cost_price                    — current replacement cost (§3.1)
 *   percent = profit ÷ net × 100, one decimal, half away from zero
 *
 * "Before delivery and COD costs": per-order costs are not a unit's (DATABASE-DESIGN
 * §5.4). No margin when the price is zero or the cost is unknown (zero) — a 100%
 * margin from a missing cost would be a lie.
 */
final class Margins
{
    public function forUnit(Money $price, Money $cost, string $vatRate = '0'): ?MarginData
    {
        if (preg_match('/^\d{1,3}(\.\d+)?$/', $vatRate) !== 1 || bccomp($vatRate, '100', 4) > 0) {
            throw new InvalidArgumentException("Not a VAT rate: [{$vatRate}]");
        }

        if ($price->isZero() || $price->isNegative() || $cost->isZero() || $cost->isNegative()) {
            return null;
        }

        $net = Money::of(self::round(bcdiv(bcmul($price->amount, '100', 8), bcadd('100', $vatRate, 8), 8), 2));
        $profit = $net->minus($cost);
        $percent = self::round(bcdiv(bcmul($profit->amount, '100', 8), $net->amount, 8), 1);

        return new MarginData($percent, $profit, $net);
    }

    /** Round a decimal string half away from zero to `$scale` places. */
    private static function round(string $value, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = str_starts_with($value, '-') ? bcsub($value, $half, $scale + 1) : bcadd($value, $half, $scale + 1);

        $rounded = bcadd($adjusted, '0', $scale);

        return $rounded === '-0'.($scale > 0 ? '.'.str_repeat('0', $scale) : '') ? substr($rounded, 1) : $rounded;
    }
}
