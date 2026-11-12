<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Product-, category- and recommendation-source profitability (P5, DATABASE-DESIGN §5.4).
 *
 * **Cohort:** the order lines of every order *delivered* in the period (by Dhaka date of
 * `orders.delivered_at`, rule 11 — revenue is recognised on delivery), with everything that
 * later happened to those lines netted in: returned units and completed refunds.
 *
 * Per line, exact to the poisha (bcmath via Money, rule 6):
 *   delivered_units = (quantity_shipped, else quantity) − quantity_returned
 *   returned_value  = line_total × quantity_returned / quantity
 *   reversal        = max(returned_value, Σ completed refund_items.amount)
 *                     — a refund that pays back a returned unit is the same money, so it is
 *                     counted once; a goodwill refund without a return still counts.
 *   net_revenue     = line_total − reversal         (line_total is after line discounts)
 *   cogs            = cost_price_snapshot × delivered_units   (rule 7: the snapshot, never today's cost)
 *   gross_profit    = net_revenue − cogs
 *
 * Shipping revenue, order-level courier/gateway costs and marketing are order costs, not
 * product costs; they live in the P&L, not here.
 */
final class Profitability
{
    /** Order statuses whose lines can carry recognised revenue. */
    public const array DELIVERED_STATUSES = ['delivered', 'completed', 'partially_returned', 'returned'];

    public const array SORTS = ['net_revenue', 'gross_profit', 'margin_percent', 'units', 'cogs', 'name'];

    /** The most rows one response returns; totals always cover every row. */
    public const int MAX_ROWS = 1000;

    public const string NO_SOURCE = 'none';

    /**
     * @param  array{category_id?: int|null, search?: string|null, source?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, truncated: bool}
     */
    public function byProduct(ReportPeriod $period, array $filters = [], string $sort = '-gross_profit'): array
    {
        return $this->report($period, $filters, $sort, static fn (object $line): array => [
            'key' => $line->product_id !== null ? 'p'.$line->product_id : 's'.$line->sku,
            'product_id' => $line->product_id === null ? null : (int) $line->product_id,
            'name' => (string) ($line->current_name ?? $line->product_name),
            'name_bn' => $line->current_name_bn ?? $line->product_name_bn,
            'sku' => $line->product_sku ?? $line->sku,
            'category_id' => $line->primary_category_id === null ? null : (int) $line->primary_category_id,
            'category_name' => $line->category_name,
            'category_name_bn' => $line->category_name_bn,
        ]);
    }

    /**
     * By the product's primary category — one product, one category, so nothing is counted
     * twice. A line whose product no longer exists lands in `category_id: null`.
     *
     * @param  array{category_id?: int|null, search?: string|null, source?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, truncated: bool}
     */
    public function byCategory(ReportPeriod $period, array $filters = [], string $sort = '-gross_profit'): array
    {
        return $this->report($period, $filters, $sort, static fn (object $line): array => [
            'key' => 'c'.($line->primary_category_id ?? 0),
            'category_id' => $line->primary_category_id === null ? null : (int) $line->primary_category_id,
            'parent_id' => $line->category_parent_id === null ? null : (int) $line->category_parent_id,
            'name' => (string) ($line->category_name ?? ''),
            'name_bn' => $line->category_name_bn,
        ], countProducts: true);
    }

    /**
     * By `order_items.recommendation_source` (rule 25d): what each placement actually sold.
     * Lines with no source are `none` — bought without a recommendation.
     *
     * @param  array{category_id?: int|null, search?: string|null, source?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, truncated: bool}
     */
    public function bySource(ReportPeriod $period, array $filters = [], string $sort = '-gross_profit'): array
    {
        return $this->report($period, $filters, $sort, static fn (object $line): array => [
            'key' => 'r'.($line->recommendation_source ?? self::NO_SOURCE),
            'source' => $line->recommendation_source ?? self::NO_SOURCE,
            'name' => (string) ($line->recommendation_source ?? self::NO_SOURCE),
        ], countProducts: true);
    }

    /**
     * @param  array{category_id?: int|null, search?: string|null, source?: string|null}  $filters
     * @param  callable(object): array<string, mixed>  $group
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>, truncated: bool}
     */
    private function report(ReportPeriod $period, array $filters, string $sort, callable $group, bool $countProducts = false): array
    {
        $groups = [];
        $all = self::emptyFigures();
        $orders = [];

        foreach ($this->lines($period, $filters)->lazyById(1000, 'order_items.id', 'id') as $line) {
            $figures = self::lineFigures($line);
            $head = $group($line);
            $key = (string) $head['key'];
            unset($head['key']);

            $groups[$key] ??= ['head' => $head, 'figures' => self::emptyFigures(), 'products' => [], 'orders' => []];
            $groups[$key]['figures'] = self::add($groups[$key]['figures'], $figures);
            $groups[$key]['orders'][(int) $line->order_id] = true;
            if ($countProducts) {
                $groups[$key]['products'][$line->product_id ?? 's'.$line->sku] = true;
            }
            $all = self::add($all, $figures);
            $orders[(int) $line->order_id] = true;
        }

        $rows = [];
        foreach ($groups as $entry) {
            $row = [...$entry['head'], ...self::present($entry['figures']), 'orders' => count($entry['orders'])];
            if ($countProducts) {
                $row['products'] = count($entry['products']);
            }
            $rows[] = $row;
        }

        $rows = self::sort($rows, $sort);
        $truncated = count($rows) > self::MAX_ROWS;

        return [
            'rows' => array_slice($rows, 0, self::MAX_ROWS),
            'totals' => [...self::present($all), 'orders' => count($orders), 'rows' => count($rows)],
            'truncated' => $truncated,
        ];
    }

    /** @param  array{category_id?: int|null, search?: string|null, source?: string|null}  $filters */
    private function lines(ReportPeriod $period, array $filters): Builder
    {
        [$start, $end] = $period->utcBounds();

        $refunds = DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.status', 'completed')
            ->groupBy('refund_items.order_item_id')
            ->select('refund_items.order_item_id')
            ->selectRaw('SUM(refund_items.amount) as refunded');

        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.primary_category_id')
            ->leftJoinSub($refunds, 'line_refunds', static function (JoinClause $join): void {
                $join->on('line_refunds.order_item_id', '=', 'order_items.id');
            })
            ->whereIn('orders.status', self::DELIVERED_STATUSES)
            ->whereNotNull('orders.delivered_at')
            ->where('orders.delivered_at', '>=', $start)
            ->where('orders.delivered_at', '<', $end)
            ->select([
                'order_items.id', 'order_items.order_id', 'order_items.product_id', 'order_items.product_name', 'order_items.product_name_bn',
                'order_items.sku', 'order_items.quantity', 'order_items.quantity_shipped', 'order_items.quantity_returned',
                'order_items.line_total', 'order_items.cost_price_snapshot', 'order_items.recommendation_source',
                'products.name as current_name', 'products.name_bn as current_name_bn', 'products.primary_category_id',
                'categories.name as category_name', 'categories.name_bn as category_name_bn', 'categories.parent_id as category_parent_id',
                'line_refunds.refunded',
            ])
            ->selectSub(
                DB::table('product_variants')->whereColumn('product_variants.product_id', 'products.id')->orderBy('product_variants.id')->limit(1)->select('product_variants.sku'),
                'product_sku',
            );

        if (($categoryId = $filters['category_id'] ?? null) !== null) {
            // The category and every category below it (nested set).
            $bounds = DB::table('categories')->where('id', $categoryId)->first(['_lft', '_rgt']);
            $query->whereIn('products.primary_category_id', DB::table('categories')
                ->where('_lft', '>=', (int) ($bounds->_lft ?? 0))
                ->where('_rgt', '<=', (int) ($bounds->_rgt ?? -1))
                ->select('id'));
        }
        if (($search = $filters['search'] ?? null) !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(static fn (Builder $q) => $q
                ->where('order_items.product_name', 'like', $like)
                ->orWhere('order_items.product_name_bn', 'like', $like)
                ->orWhere('order_items.sku', 'like', $like));
        }
        if (($source = $filters['source'] ?? null) !== null) {
            $source === self::NO_SOURCE
                ? $query->whereNull('order_items.recommendation_source')
                : $query->where('order_items.recommendation_source', $source);
        }

        return $query;
    }

    /** @return array{units: int, units_returned: int, units_sold: int, gross_revenue: Money, reversals: Money, net_revenue: Money, cogs: Money, gross_profit: Money} */
    public static function lineFigures(object $line): array
    {
        $quantity = max((int) $line->quantity, 0);
        $returned = min(max((int) $line->quantity_returned, 0), $quantity);
        $shipped = (int) $line->quantity_shipped > 0 ? (int) $line->quantity_shipped : $quantity;
        $units = max($shipped - $returned, 0);

        $lineTotal = ReportMoney::of($line->line_total);
        $returnedValue = $quantity > 0 && $returned > 0
            ? Money::of(ReportMoney::round(bcdiv(bcmul($lineTotal->toDecimal(), (string) $returned, 8), (string) $quantity, 8)))
            : Money::zero();
        $refunded = ReportMoney::of($line->refunded ?? null);
        $reversal = $refunded->greaterThan($returnedValue) ? $refunded : $returnedValue;
        $netRevenue = $lineTotal->minus($reversal);

        $snapshot = is_scalar($line->cost_price_snapshot) ? (string) $line->cost_price_snapshot : '0';
        $cogs = Money::of(ReportMoney::round(bcmul(self::decimal($snapshot), (string) $units, 8)));

        return [
            'units' => $units,
            'units_returned' => $returned,
            'units_sold' => $quantity,
            'gross_revenue' => $lineTotal,
            'reversals' => $reversal,
            'net_revenue' => $netRevenue,
            'cogs' => $cogs,
            'gross_profit' => $netRevenue->minus($cogs),
        ];
    }

    /** @return array{units: int, units_returned: int, units_sold: int, gross_revenue: Money, reversals: Money, net_revenue: Money, cogs: Money, gross_profit: Money} */
    private static function emptyFigures(): array
    {
        return ['units' => 0, 'units_returned' => 0, 'units_sold' => 0, 'gross_revenue' => Money::zero(), 'reversals' => Money::zero(), 'net_revenue' => Money::zero(), 'cogs' => Money::zero(), 'gross_profit' => Money::zero()];
    }

    /**
     * @param  array{units: int, units_returned: int, units_sold: int, gross_revenue: Money, reversals: Money, net_revenue: Money, cogs: Money, gross_profit: Money}  $a
     * @param  array{units: int, units_returned: int, units_sold: int, gross_revenue: Money, reversals: Money, net_revenue: Money, cogs: Money, gross_profit: Money}  $b
     * @return array{units: int, units_returned: int, units_sold: int, gross_revenue: Money, reversals: Money, net_revenue: Money, cogs: Money, gross_profit: Money}
     */
    private static function add(array $a, array $b): array
    {
        return [
            'units' => $a['units'] + $b['units'],
            'units_returned' => $a['units_returned'] + $b['units_returned'],
            'units_sold' => $a['units_sold'] + $b['units_sold'],
            'gross_revenue' => $a['gross_revenue']->plus($b['gross_revenue']),
            'reversals' => $a['reversals']->plus($b['reversals']),
            'net_revenue' => $a['net_revenue']->plus($b['net_revenue']),
            'cogs' => $a['cogs']->plus($b['cogs']),
            'gross_profit' => $a['gross_profit']->plus($b['gross_profit']),
        ];
    }

    /**
     * @param  array{units: int, units_returned: int, units_sold: int, gross_revenue: Money, reversals: Money, net_revenue: Money, cogs: Money, gross_profit: Money}  $f
     * @return array<string, mixed>
     */
    private static function present(array $f): array
    {
        return [...$f, 'margin_percent' => ReportMoney::percent($f['gross_profit'], $f['net_revenue'])];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sort(array $rows, string $sort): array
    {
        $descending = str_starts_with($sort, '-');
        $field = ltrim($sort, '-');

        usort($rows, static function (array $a, array $b) use ($field, $descending): int {
            $cmp = match ($field) {
                'name' => strcmp(mb_strtolower((string) $a['name']), mb_strtolower((string) $b['name'])),
                'units' => $a['units'] <=> $b['units'],
                'margin_percent' => bccomp((string) ($a['margin_percent'] ?? '-99999999'), (string) ($b['margin_percent'] ?? '-99999999'), 2),
                default => $a[$field]->compareTo($b[$field]),
            };
            $cmp = $descending ? -$cmp : $cmp;

            return $cmp !== 0 ? $cmp : strcmp((string) $a['name'], (string) $b['name']);
        });

        return $rows;
    }

    private static function decimal(string $value): string
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : sprintf('%.4F', (float) $value);
    }
}
