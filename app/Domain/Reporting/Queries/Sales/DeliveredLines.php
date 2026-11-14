<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Sales;

use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Reporting\Support\DateRange;
use App\Domain\Reporting\Support\Rates;
use App\Support\Money;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Category, brand and recommendation-source performance over the order lines of orders
 * **delivered** in the range — the same cohort and the same per-line arithmetic as the
 * finance profitability report ({@see Profitability::lineFigures()}: returns and completed
 * refunds netted, COGS from `cost_price_snapshot`, rule 7), so the category totals here
 * equal `Profitability::byCategory()` to the poisha. One pass groups by all three keys.
 *
 * Line revenue is product revenue after line and order discounts' allocation into
 * line_total; delivery charges are order revenue and live in the P&L, not here.
 */
final readonly class DeliveredLines
{
    public const string NO_BRAND = 'none';

    /**
     * @return array{categories: list<array<string, mixed>>, brands: list<array<string, mixed>>, sources: array<string, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function collect(DateRange $range): array
    {
        $categories = [];
        $brands = [];
        $sources = [];
        $all = self::empty();

        // One streamed query (cursor), not lazyById: re-running the joins per 1,000-row chunk cost
        // ~25× the query time over a year of lines (measured, see the P8 report).
        foreach ($this->lines($range)->orderBy('order_items.id')->cursor() as $line) {
            $figures = Profitability::lineFigures($line);
            $orderId = (int) $line->order_id;

            $categoryKey = 'c'.($line->primary_category_id ?? 0);
            $categories[$categoryKey] ??= ['head' => [
                'category_id' => $line->primary_category_id === null ? null : (int) $line->primary_category_id,
                'name' => (string) ($line->category_name ?? ''),
                'name_bn' => $line->category_name_bn,
            ], 'figures' => self::empty()];
            self::add($categories[$categoryKey]['figures'], $figures, $orderId);

            $brandKey = 'b'.($line->brand_id ?? 0);
            $brands[$brandKey] ??= ['head' => [
                'brand_id' => $line->brand_id === null ? null : (int) $line->brand_id,
                'name' => $line->brand_id === null ? null : (string) $line->brand_name,
                'name_bn' => $line->brand_name_bn,
            ], 'figures' => self::empty()];
            self::add($brands[$brandKey]['figures'], $figures, $orderId);

            $source = is_string($line->recommendation_source) && $line->recommendation_source !== '' ? $line->recommendation_source : SalesAnalytics::NO_SOURCE;
            $sources[$source] ??= self::empty();
            self::add($sources[$source], $figures, $orderId);

            self::add($all, $figures, $orderId);
        }

        $present = static fn (array $groups): array => self::sortByRevenue(array_values(array_map(
            static fn (array $g): array => [...$g['head'], ...self::present($g['figures'])],
            $groups,
        )));

        return [
            'categories' => $present($categories),
            'brands' => $present($brands),
            'sources' => array_map(self::present(...), $sources),
            'totals' => self::present($all),
        ];
    }

    private function lines(DateRange $range): \Illuminate\Database\Query\Builder
    {
        [$start, $end] = $range->utcBounds();

        $refunds = DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.status', 'completed')
            ->groupBy('refund_items.order_item_id')
            ->select('refund_items.order_item_id')
            ->selectRaw('SUM(refund_items.amount) as refunded');

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.primary_category_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoinSub($refunds, 'line_refunds', static function (JoinClause $join): void {
                $join->on('line_refunds.order_item_id', '=', 'order_items.id');
            })
            ->whereIn('orders.status', Profitability::DELIVERED_STATUSES)
            ->whereNotNull('orders.delivered_at')
            ->where('orders.delivered_at', '>=', $start)
            ->where('orders.delivered_at', '<', $end)
            ->select([
                'order_items.id', 'order_items.order_id', 'order_items.quantity', 'order_items.quantity_shipped', 'order_items.quantity_returned',
                'order_items.line_total', 'order_items.cost_price_snapshot', 'order_items.recommendation_source',
                'products.primary_category_id', 'products.brand_id',
                'categories.name as category_name', 'categories.name_bn as category_name_bn',
                'brands.name as brand_name', 'brands.name_bn as brand_name_bn',
                'line_refunds.refunded',
            ]);
    }

    /** @return array{units: int, units_returned: int, units_sold: int, net_revenue: Money, cogs: Money, gross_profit: Money, orders: array<int, true>} */
    private static function empty(): array
    {
        return ['units' => 0, 'units_returned' => 0, 'units_sold' => 0, 'net_revenue' => Money::zero(), 'cogs' => Money::zero(), 'gross_profit' => Money::zero(), 'orders' => []];
    }

    /**
     * @param  array{units: int, units_returned: int, units_sold: int, net_revenue: Money, cogs: Money, gross_profit: Money, orders: array<int, true>}  $a
     * @param  array{units: int, units_returned: int, units_sold: int, net_revenue: Money, cogs: Money, gross_profit: Money}  $line
     */
    private static function add(array &$a, array $line, int $orderId): void
    {
        // By reference (Phase 9, docs/audits/PERFORMANCE.md P-05): by value, writing the `orders`
        // set copied it — up to one entry per order in the range — on every line, O(n²): 40 s
        // of a year's sales analytics at 90k lines.
        $a['units'] += $line['units'];
        $a['units_returned'] += $line['units_returned'];
        $a['units_sold'] += $line['units_sold'];
        $a['net_revenue'] = $a['net_revenue']->plus($line['net_revenue']);
        $a['cogs'] = $a['cogs']->plus($line['cogs']);
        $a['gross_profit'] = $a['gross_profit']->plus($line['gross_profit']);
        $a['orders'][$orderId] = true;
    }

    /**
     * @param  array{units: int, units_returned: int, units_sold: int, net_revenue: Money, cogs: Money, gross_profit: Money, orders: array<int, true>}  $f
     * @return array<string, mixed>
     */
    private static function present(array $f): array
    {
        return [
            'orders' => count($f['orders']),
            'units_sold' => $f['units_sold'],
            'units_returned' => $f['units_returned'],
            'units' => $f['units'],
            'net_revenue' => $f['net_revenue'],
            'cogs' => $f['cogs'],
            'gross_profit' => $f['gross_profit'],
            'margin_percent' => ReportMoney::percent($f['gross_profit'], $f['net_revenue']),
            'return_rate' => Rates::percent($f['units_returned'], $f['units_sold']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sortByRevenue(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $cmp = $b['net_revenue']->compareTo($a['net_revenue']);

            return $cmp !== 0 ? $cmp : strcmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }
}
