<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Products;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Inventory\Services\StockValuation;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Reporting\Support\DateRange;
use App\Domain\Reporting\Support\Rates;
use App\Support\Money;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Product analytics (P10; Figma B · 24 Analytics, Products tab 522:80395). Read-only; every
 * figure is computed here (rule 1) over Dhaka days (rule 11).
 *
 * Three measures, each with one definition:
 *
 *   delivered   the order lines of orders **delivered** in the range — the same cohort and
 *               per-line arithmetic as the profitability report ({@see Profitability::lineFigures()}):
 *               revenue net of returns and completed refunds, COGS from cost_price_snapshot
 *               (rule 7). "Units delivered" is the lines' quantity; "came back" is
 *               quantity_returned of those lines; return rate is returned ÷ delivered.
 *   pace        units on orders **placed** in the range and not cancelled — demand, which is
 *               what empties the shelf. Days of stock left = available ÷ (pace units ÷ days in
 *               the range), floored to whole days; null when nothing was placed.
 *   stock       available now = Σ (on_hand − reserved) over every variant and location, never
 *               below 0; stock value at cost = Σ on_hand × the location's cost basis
 *               ({@see StockValuation}).
 *
 * "Products sold" counts the products with a delivered line; "on sale" counts active
 * products. "Running out" lists active products with pace and fewer than
 * {@see self::RUNNING_OUT_DAYS} days left; "not selling" lists active products with stock
 * and no placed (non-cancelled) line in the range. Cost, COGS, profit, margin and stock value
 * are null unless the caller says the viewer may see cost (`$withProfit`).
 */
final readonly class ProductAnalytics
{
    /** Rows in the best-sellers table. */
    public const int TOP = 20;

    /** Rows in each side card. */
    public const int LIST = 10;

    /** Fewer days of stock than this is "about to run out". */
    public const int RUNNING_OUT_DAYS = 14;

    /** Fewer days than this is "fast seller about to run out" in the plain-words summary. */
    public const int URGENT_DAYS = 7;

    /** A product needs this many delivered units before its return rate is called out. */
    public const int MIN_UNITS_FOR_RETURNS = 3;

    /** Best-seller orderings. */
    public const array SORTS = ['revenue', 'units', 'margin', 'returns'];

    public const string DEFAULT_SORT = 'revenue';

    /** @return array<string, mixed> */
    public function report(DateRange $range, bool $withProfit, string $sort = self::DEFAULT_SORT): array
    {
        $previousRange = $range->previous();

        $delivered = $this->delivered($range);
        $previousDelivered = $this->delivered($previousRange);
        $pace = $this->pace($range);
        $stock = $this->stock();
        $products = $this->activeProducts();

        $rows = [];
        foreach ($delivered['products'] as $p) {
            $productId = $p['product_id'];
            $rows[] = [
                ...$this->head($p, $productId !== null ? ($products[$productId] ?? null) : null),
                ...self::figures($p['figures'], $withProfit),
                ...$this->stockFigures($productId, $stock, $pace, $range, $withProfit),
            ];
        }

        // A product sold in the range but no longer active still appears in the best sellers;
        // the stock side cards are for products on sale now.
        $runningOut = [];
        $notSelling = [];
        foreach ($products as $id => $product) {
            $figures = $this->stockFigures($id, $stock, $pace, $range, $withProfit);
            $row = [...$this->head(['product_id' => $id, 'name' => $product->name, 'name_bn' => $product->name_bn], $product), ...$figures];
            if ($figures['days_of_stock'] !== null && $figures['days_of_stock'] < self::RUNNING_OUT_DAYS) {
                $runningOut[] = $row;
            }
            if (($pace[$id] ?? 0) === 0 && $figures['stock_left'] > 0) {
                $notSelling[] = $row;
            }
        }
        usort($runningOut, static fn (array $a, array $b): int => [$a['days_of_stock'], $a['stock_left'], $a['name']] <=> [$b['days_of_stock'], $b['stock_left'], $b['name']]);
        usort($notSelling, static fn (array $a, array $b): int => [$b['stock_left'], $a['name']] <=> [$a['stock_left'], $b['name']]);
        if ($withProfit) {
            usort($notSelling, static fn (array $a, array $b): int => ReportMoney::of($b['stock_value'])->compareTo(ReportMoney::of($a['stock_value'])) ?: strcmp((string) $a['name'], (string) $b['name']));
        }

        $totals = $delivered['totals'];
        $previousTotals = $previousDelivered['totals'];
        $urgent = count(array_filter($runningOut, static fn (array $r): bool => $r['days_of_stock'] < self::URGENT_DAYS));

        $byRevenue = self::sorted($rows, 'revenue');
        $mostReturned = null;
        foreach ($rows as $row) {
            if ($row['units_delivered'] < self::MIN_UNITS_FOR_RETURNS || $row['units_returned'] === 0) {
                continue;
            }
            if ($mostReturned === null || bccomp((string) $row['return_rate'], (string) $mostReturned['return_rate'], 2) > 0
                || (bccomp((string) $row['return_rate'], (string) $mostReturned['return_rate'], 2) === 0 && $row['units_returned'] > $mostReturned['units_returned'])) {
                $mostReturned = $row;
            }
        }

        return [
            'range' => $range->toArray(),
            'comparison' => $previousRange->toArray(),
            'profit_visible' => $withProfit,
            'sort' => $sort,
            'thresholds' => [
                'running_out_days' => self::RUNNING_OUT_DAYS,
                'urgent_days' => self::URGENT_DAYS,
                'min_units_for_returns' => self::MIN_UNITS_FOR_RETURNS,
            ],
            'summary' => [
                'products_sold' => count($rows),
                'products_on_sale' => count($products),
                'units_delivered' => $totals['units_sold'],
                'units_delivered_previous' => $previousTotals['units_sold'],
                'units_delivered_change' => $totals['units_sold'] - $previousTotals['units_sold'],
                'units_delta' => Rates::countDelta($totals['units_sold'], $previousTotals['units_sold']),
                'net_revenue' => $totals['net_revenue'],
                'gross_profit' => $withProfit ? $totals['gross_profit'] : null,
                'margin_percent' => $withProfit ? ReportMoney::percent($totals['gross_profit'], $totals['net_revenue']) : null,
                'units_returned' => $totals['units_returned'],
                'return_rate' => Rates::percent($totals['units_returned'], $totals['units_sold']),
                'running_out_count' => count($runningOut),
                'running_out_urgent_count' => $urgent,
                'not_selling_count' => count($notSelling),
            ],
            'highlights' => [
                'best_seller' => $byRevenue[0] ?? null,
                'most_returned' => $mostReturned,
            ],
            'products' => array_slice(self::sorted($rows, $sort === 'margin' && ! $withProfit ? 'revenue' : $sort), 0, self::TOP),
            'running_out' => array_slice($runningOut, 0, self::LIST),
            'not_selling' => array_slice($notSelling, 0, self::LIST),
        ];
    }

    /**
     * Delivered lines grouped by product (a line whose product was deleted groups by its
     * snapshot name).
     *
     * @return array{products: array<string, array{product_id: int|null, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null, figures: array<string, mixed>}>, totals: array{units_sold: int, units_returned: int, units: int, net_revenue: Money, cogs: Money, gross_profit: Money}}
     */
    private function delivered(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();

        $refunds = DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.status', 'completed')
            ->groupBy('refund_items.order_item_id')
            ->select('refund_items.order_item_id')
            ->selectRaw('SUM(refund_items.amount) as refunded');

        $lines = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.primary_category_id')
            ->leftJoinSub($refunds, 'line_refunds', static function (JoinClause $join): void {
                $join->on('line_refunds.order_item_id', '=', 'order_items.id');
            })
            ->whereIn('orders.status', Profitability::DELIVERED_STATUSES)
            ->whereNotNull('orders.delivered_at')
            ->where('orders.delivered_at', '>=', $start)
            ->where('orders.delivered_at', '<', $end)
            ->orderBy('order_items.id')
            ->select([
                'order_items.id', 'order_items.order_id', 'order_items.product_id', 'order_items.product_name', 'order_items.product_name_bn',
                'order_items.quantity', 'order_items.quantity_shipped', 'order_items.quantity_returned',
                'order_items.line_total', 'order_items.cost_price_snapshot',
                'products.name as name', 'products.name_bn as name_bn',
                'categories.name as category_name', 'categories.name_bn as category_name_bn',
                'line_refunds.refunded',
            ]);

        $products = [];
        $totals = self::emptyFigures();
        foreach ($lines->cursor() as $line) {
            $figures = Profitability::lineFigures($line);
            $productId = $line->product_id === null ? null : (int) $line->product_id;
            $key = $productId !== null ? 'p'.$productId : 'n:'.(string) $line->product_name;
            $products[$key] ??= [
                'product_id' => $productId,
                'name' => is_string($line->name) ? $line->name : (string) $line->product_name,
                'name_bn' => is_string($line->name) ? (is_string($line->name_bn) ? $line->name_bn : null) : (is_string($line->product_name_bn) ? $line->product_name_bn : null),
                'category_name' => is_string($line->category_name) ? $line->category_name : null,
                'category_name_bn' => is_string($line->category_name_bn) ? $line->category_name_bn : null,
                'figures' => self::emptyFigures(),
            ];
            self::add($products[$key]['figures'], $figures);
            self::add($totals, $figures);
        }

        return ['products' => $products, 'totals' => $totals];
    }

    /**
     * Units on orders placed in the range and not cancelled, by product.
     *
     * @return array<int, int>
     */
    private function pace(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('order_items.product_id')
            ->where('orders.placed_at', '>=', $start)
            ->where('orders.placed_at', '<', $end)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('order_items.product_id')
            ->select('order_items.product_id')
            ->selectRaw('SUM(order_items.quantity) as units')
            ->get()
            ->mapWithKeys(static fn (object $r): array => [(int) $r->product_id => (int) $r->units])
            ->all();
    }

    /**
     * Available units and stock value at cost by product, over every variant and location.
     *
     * @return array<int, array{available: int, value: string}>
     */
    private function stock(): array
    {
        $levels = DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
            ->orderBy('stock_levels.id')
            ->select(['product_variants.product_id', 'stock_levels.on_hand', 'stock_levels.reserved', 'stock_levels.avg_cost', 'product_variants.cost_price'])
            ->cursor();

        $stock = [];
        foreach ($levels as $level) {
            $id = (int) $level->product_id;
            $stock[$id] ??= ['available' => 0, 'value' => '0.00'];
            $onHand = (int) $level->on_hand;
            $stock[$id]['available'] += $onHand - (int) $level->reserved;
            if ($onHand > 0) {
                $avg = is_scalar($level->avg_cost) ? (string) $level->avg_cost : null;
                $cost = is_scalar($level->cost_price) ? (string) $level->cost_price : null;
                $stock[$id]['value'] = StockValuation::add($stock[$id]['value'], StockValuation::value($onHand, StockValuation::costBasis($avg, $cost)));
            }
        }

        return $stock;
    }

    /** @return array<int, object{id: int, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null}> */
    private function activeProducts(): array
    {
        /** @var array<int, object{id: int, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null}> */
        return DB::table('products')
            ->leftJoin('categories', 'categories.id', '=', 'products.primary_category_id')
            ->where('products.status', ProductStatus::Active->value)
            ->whereNull('products.deleted_at')
            ->orderBy('products.id')
            ->select(['products.id', 'products.name', 'products.name_bn', 'categories.name as category_name', 'categories.name_bn as category_name_bn'])
            ->get()
            ->keyBy(static fn (object $p): int => (int) $p->id)
            ->all();
    }

    /**
     * @param  array{product_id: int|null, name: string, name_bn: string|null, category_name?: string|null, category_name_bn?: string|null}  $p
     * @return array{product_id: int|null, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null}
     */
    private function head(array $p, ?object $product): array
    {
        return [
            'product_id' => $p['product_id'],
            'name' => $p['name'],
            'name_bn' => $p['name_bn'],
            'category_name' => $p['category_name'] ?? (isset($product->category_name) && is_string($product->category_name) ? $product->category_name : null),
            'category_name_bn' => $p['category_name_bn'] ?? (isset($product->category_name_bn) && is_string($product->category_name_bn) ? $product->category_name_bn : null),
        ];
    }

    /**
     * @param  array<int, array{available: int, value: string}>  $stock
     * @param  array<int, int>  $pace
     * @return array{units_paced: int, stock_left: int, days_of_stock: int|null, stock_value: string|null}
     */
    private function stockFigures(?int $productId, array $stock, array $pace, DateRange $range, bool $withProfit): array
    {
        if ($productId === null) {
            return ['units_paced' => 0, 'stock_left' => 0, 'days_of_stock' => null, 'stock_value' => null];
        }
        $available = max($stock[$productId]['available'] ?? 0, 0);
        $units = $pace[$productId] ?? 0;

        return [
            'units_paced' => $units,
            'stock_left' => $available,
            // available ÷ (units ÷ days) = available × days ÷ units, floored to whole days.
            'days_of_stock' => $units > 0 ? intdiv($available * $range->days(), $units) : null,
            'stock_value' => $withProfit ? ($stock[$productId]['value'] ?? '0.00') : null,
        ];
    }

    /**
     * @param  array{units_sold: int, units_returned: int, units: int, net_revenue: Money, cogs: Money, gross_profit: Money}  $f
     * @return array<string, mixed>
     */
    private static function figures(array $f, bool $withProfit): array
    {
        return [
            'units_delivered' => $f['units_sold'],
            'units_returned' => $f['units_returned'],
            'units_kept' => $f['units'],
            'net_revenue' => $f['net_revenue'],
            'cogs' => $withProfit ? $f['cogs'] : null,
            'gross_profit' => $withProfit ? $f['gross_profit'] : null,
            'margin_percent' => $withProfit ? ReportMoney::percent($f['gross_profit'], $f['net_revenue']) : null,
            'return_rate' => Rates::percent($f['units_returned'], $f['units_sold']),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $rows, string $sort): array
    {
        $money = static fn (mixed $v): Money => $v instanceof Money ? $v : ReportMoney::of($v);
        $revenue = static fn (array $a, array $b): int => $money($b['net_revenue'])->compareTo($money($a['net_revenue']));
        $percent = static fn (mixed $v): string => is_string($v) ? $v : '-1000000';
        usort($rows, static function (array $a, array $b) use ($sort, $revenue, $percent): int {
            $cmp = match ($sort) {
                'units' => $b['units_delivered'] <=> $a['units_delivered'],
                'margin' => bccomp($percent($b['margin_percent']), $percent($a['margin_percent']), 2),
                'returns' => bccomp($percent($b['return_rate']), $percent($a['return_rate']), 2) ?: $b['units_returned'] <=> $a['units_returned'],
                default => 0,
            };

            return $cmp ?: ($revenue($a, $b) ?: strcmp((string) $a['name'], (string) $b['name']));
        });

        return $rows;
    }

    /** @return array{units_sold: int, units_returned: int, units: int, net_revenue: Money, cogs: Money, gross_profit: Money} */
    private static function emptyFigures(): array
    {
        return ['units_sold' => 0, 'units_returned' => 0, 'units' => 0, 'net_revenue' => Money::zero(), 'cogs' => Money::zero(), 'gross_profit' => Money::zero()];
    }

    /**
     * @param  array{units_sold: int, units_returned: int, units: int, net_revenue: Money, cogs: Money, gross_profit: Money}  $a
     * @param  array{units: int, units_returned: int, units_sold: int, net_revenue: Money, cogs: Money, gross_profit: Money}  $line
     */
    private static function add(array &$a, array $line): void
    {
        $a['units_sold'] += $line['units_sold'];
        $a['units_returned'] += $line['units_returned'];
        $a['units'] += $line['units'];
        $a['net_revenue'] = $a['net_revenue']->plus($line['net_revenue']);
        $a['cogs'] = $a['cogs']->plus($line['cogs']);
        $a['gross_profit'] = $a['gross_profit']->plus($line['gross_profit']);
    }
}
