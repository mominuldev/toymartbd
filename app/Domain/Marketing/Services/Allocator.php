<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use App\Support\Money;

/**
 * Splits an order-level discount across lines in proportion to their amounts, without
 * losing or inventing a poisha (Money::allocate), and never giving a line more than
 * its amount.
 */
final class Allocator
{
    /**
     * @param  array<int, Money>  $bases  key → amount the share is proportional to (and capped at)
     * @return array<int, Money> key → share; shares sum to min($total, Σ bases)
     */
    public static function spread(Money $total, array $bases): array
    {
        $bases = array_filter($bases, static fn (Money $m): bool => $m->greaterThan(Money::zero()));
        if ($bases === [] || ! $total->greaterThan(Money::zero())) {
            return [];
        }

        $sum = array_reduce($bases, static fn (Money $carry, Money $m): Money => $carry->plus($m), Money::zero());
        if (! $total->lessThan($sum)) {
            return $bases;
        }

        $keys = array_keys($bases);
        $shares = $total->allocate(...array_map(static fn (Money $m): int => $m->toPoisha(), array_values($bases)));

        return array_combine($keys, $shares);
    }

    /**
     * @param  array<int, Money>  $amounts
     */
    public static function sum(array $amounts): Money
    {
        return array_reduce($amounts, static fn (Money $carry, Money $m): Money => $carry->plus($m), Money::zero());
    }

    /**
     * Caps a per-line discount map at `$cap`, re-spreading proportionally when over.
     *
     * @param  array<int, Money>  $perLine
     * @return array<int, Money>
     */
    public static function cap(array $perLine, ?Money $cap): array
    {
        if ($cap === null || ! self::sum($perLine)->greaterThan($cap)) {
            return $perLine;
        }

        return self::spread($cap, $perLine);
    }
}
