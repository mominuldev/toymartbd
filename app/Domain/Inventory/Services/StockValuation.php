<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

/**
 * Inventory valuation at cost (06-admin-feature-checklist §2.7).
 *
 * The cost basis of a unit is the location's weighted-average cost
 * (`stock_levels.avg_cost`, maintained on purchase receipt — DATABASE-DESIGN §4.6,
 * §5.3) when one exists, and otherwise the variant's current replacement cost
 * (`product_variants.cost_price`). Until purchasing ships no receipt has set avg_cost,
 * so the fallback is what values opening stock. Arithmetic is bcmath on decimal
 * strings, never float (rule 6).
 */
final class StockValuation
{
    /** SQL for the per-unit cost basis; needs `stock_levels` and `product_variants` joined. */
    public const string COST_BASIS_SQL = 'CASE WHEN stock_levels.avg_cost > 0 THEN stock_levels.avg_cost ELSE product_variants.cost_price END';

    /** The cost basis of one unit, as a 4-dp decimal string. */
    public static function costBasis(string|int|float|null $avgCost, string|int|float|null $costPrice): string
    {
        $avg = self::decimal($avgCost);

        return bccomp($avg, '0', 4) > 0 ? $avg : self::decimal($costPrice);
    }

    /** on_hand × unit cost, rounded half away from zero to the poisha. */
    public static function value(int $onHand, string $unitCost): string
    {
        return self::round(bcmul((string) $onHand, $unitCost, 6));
    }

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, 2);
    }

    private static function decimal(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd(is_float($value) ? sprintf('%.4F', $value) : (string) $value, '0', 4);
    }

    private static function round(string $value): string
    {
        $half = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $half, 6), '0', 2);
    }
}
