<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Services;

use App\Support\Money;
use InvalidArgumentException;

/**
 * Splits a landed-cost total across receipt lines in proportion to a weight (line value,
 * quantity or weight), exact to the poisha: the shares always sum to the total.
 *
 * Each line first gets the floor of its exact share in poisha; the poisha left over go
 * one at a time to the lines with the largest fractional remainders, and on a tie to the
 * earlier line. The result depends only on the inputs and their order, so a quote and
 * the receipt it previews allocate identically.
 *
 * Arithmetic is bcmath on decimal strings (rule 6): no float, and no integer overflow on
 * large freight × large line values (Money::allocate multiplies ints).
 */
final class LandedCostApportioner
{
    /**
     * @param  list<string>  $weights  non-negative decimal strings, one per line
     * @return list<Money> one share per weight, in the same order
     *
     * @throws InvalidArgumentException when a weight is negative, or the total is non-zero and every weight is zero
     */
    public static function apportion(Money $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        if ($total->isNegative()) {
            throw new InvalidArgumentException('A landed cost is never negative.');
        }

        $sum = '0';
        foreach ($weights as $weight) {
            if (preg_match('/^\d+(\.\d+)?$/', $weight) !== 1) {
                throw new InvalidArgumentException("Not a non-negative weight: [{$weight}]");
            }
            $sum = bcadd($sum, $weight, 8);
        }

        $poisha = $total->toPoisha();
        if ($poisha === 0) {
            return array_map(static fn (): Money => Money::zero(), $weights);
        }
        if (bccomp($sum, '0', 8) === 0) {
            throw new InvalidArgumentException('Every apportioning weight is zero.');
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $i => $weight) {
            $exact = bcdiv(bcmul((string) $poisha, $weight, 8), $sum, 12);
            $floor = bcadd($exact, '0', 0);
            $shares[$i] = (int) $floor;
            $remainders[$i] = bcsub($exact, $floor, 12);
        }

        $left = $poisha - array_sum($shares);
        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => bccomp($remainders[$b], $remainders[$a], 12) ?: $a <=> $b);

        foreach ($order as $i) {
            if ($left <= 0) {
                break;
            }
            $shares[$i]++;
            $left--;
        }

        return array_map(Money::fromPoisha(...), array_values($shares));
    }
}
