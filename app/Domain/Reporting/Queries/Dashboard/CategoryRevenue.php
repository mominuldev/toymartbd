<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Reporting\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * "Where the money came from" (04-admin-spec B1 R4b): recognised item revenue by top-level
 * category over the orders **delivered** in the range.
 *
 * Built on {@see Profitability::byCategory()} — the profitability report's own `net_revenue`
 * per primary category (line value after line discounts, less returns and completed refunds),
 * so the donut and the profitability screen never disagree. Each primary category is rolled
 * up to its root so the ring has a handful of slices; beyond SLICES − 1 roots the rest are
 * one "other" slice.
 *
 * This is item revenue: delivery revenue and order-level coupon discounts are order lines in
 * the P&L, not product lines, so the donut total is not the revenue KPI (the screen says so).
 */
final readonly class CategoryRevenue
{
    public const int SLICES = 7;

    public function __construct(private Profitability $profitability) {}

    /**
     * @return array{total: Money, slices: list<array{category_id: int|null, name: string|null, name_bn: string|null, other: bool, amount: Money, share_percent: string|null}>}
     */
    public function slices(DateRange $range): array
    {
        $rows = $this->profitability->byCategory($range->period())['rows'];

        $parents = DB::table('categories')->pluck('parent_id', 'id')->all();
        $names = [];

        $roots = [];
        foreach ($rows as $row) {
            $id = $row['category_id'];
            $root = $id === null ? null : self::root((int) $id, $parents);
            $key = $root ?? 0;
            $roots[$key] ??= ['category_id' => $root, 'amount' => Money::zero()];
            $roots[$key]['amount'] = $roots[$key]['amount']->plus($row['net_revenue']);
            if ($root !== null) {
                $names[$root] = true;
            }
        }

        $labels = DB::table('categories')->whereIn('id', array_keys($names))->get(['id', 'name', 'name_bn'])->keyBy('id');

        $list = array_values(array_filter($roots, static fn (array $r): bool => ! $r['amount']->isZero()));
        usort($list, static fn (array $a, array $b): int => $b['amount']->compareTo($a['amount']) ?: ($a['category_id'] ?? PHP_INT_MAX) <=> ($b['category_id'] ?? PHP_INT_MAX));

        $total = Money::zero();
        foreach ($list as $slice) {
            $total = $total->plus($slice['amount']);
        }

        $keep = count($list) > self::SLICES ? self::SLICES - 1 : self::SLICES;
        $slices = [];
        $other = Money::zero();
        foreach ($list as $index => $slice) {
            if ($index >= $keep || $slice['category_id'] === null) {
                $other = $other->plus($slice['amount']);

                continue;
            }
            $label = $labels->get($slice['category_id']);
            $slices[] = [
                'category_id' => $slice['category_id'],
                'name' => $label === null ? null : (string) $label->name,
                'name_bn' => $label?->name_bn === null ? null : (string) $label->name_bn,
                'other' => false,
                'amount' => $slice['amount'],
                'share_percent' => ReportMoney::percent($slice['amount'], $total),
            ];
        }
        if (! $other->isZero()) {
            $slices[] = ['category_id' => null, 'name' => null, 'name_bn' => null, 'other' => true, 'amount' => $other, 'share_percent' => ReportMoney::percent($other, $total)];
        }

        return ['total' => $total, 'slices' => $slices];
    }

    /** @param  array<int|string, mixed>  $parents id => parent_id */
    private static function root(int $id, array $parents): int
    {
        $seen = [];
        while (isset($parents[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $id = (int) $parents[$id];
        }

        return $id;
    }
}
