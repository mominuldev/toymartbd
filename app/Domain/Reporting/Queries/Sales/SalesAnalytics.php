<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Sales;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Reporting\Support\DateRange;
use App\Domain\Reporting\Support\Rates;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sales analytics (Phase 8, roadmap row 2; Figma B · 24 Analytics 241:30584, Sales tab).
 * Read-only; every figure is computed here (rule 1), every day is a Dhaka day (rule 11),
 * weeks run Saturday–Friday (DateRange / ReportPeriod).
 *
 * Two different things are measured and never mixed (rule 10):
 *
 *   placed      demand: orders placed in the range at their grand_total ({@see PlacedOrders}).
 *               Not revenue. Cancelled orders are reported apart.
 *   recognised  revenue recognised on delivery, from the ledger through the finance P&L
 *               ({@see ProfitAndLoss::series()}) — never re-derived from orders.
 *
 * Category / brand / recommendation performance uses the delivered-lines cohort of the
 * profitability report ({@see DeliveredLines}). Gross profit and COGS are only included when
 * the caller says the viewer may see cost (`$withProfit`); otherwise they are null.
 *
 * Conversion rate is deliberately absent: the store records no page_view server-side and
 * browser events are consent-gated (opt_in by default, docs/api/tracking.md), so there is
 * no defensible session denominator. The response says so in `conversion`.
 */
final readonly class SalesAnalytics
{
    public const string NO_SOURCE = 'none';

    /** Granularities the sales report offers (a year bucket is the P&L's, not ours). */
    public const array GRANULARITIES = ['day', 'week', 'month'];

    public function __construct(
        private ProfitAndLoss $pnl,
        private PlacedOrders $placed,
        private DeliveredLines $lines,
    ) {}

    /** The bucket size a range gets when the caller does not choose: day ≤ 31 days, week ≤ 186, else month. */
    public static function defaultGranularity(DateRange $range): ReportGranularity
    {
        return match (true) {
            $range->days() <= 31 => ReportGranularity::Day,
            $range->days() <= 186 => ReportGranularity::Week,
            default => ReportGranularity::Month,
        };
    }

    /** @return array<string, mixed> */
    public function report(DateRange $range, ReportGranularity $granularity, bool $withProfit): array
    {
        $previousRange = $range->previous();

        $placed = $this->placed->collect($range, $granularity);
        $previousPlaced = $this->placed->collect($previousRange, $granularity);
        $series = $this->pnl->series($range->period(), $granularity);
        $previousSeries = $this->pnl->series($previousRange->period(), $granularity);
        $delivered = $this->delivered($range, $granularity);
        $previousDelivered = $this->delivered($previousRange, null);
        $lines = $this->lines->collect($range);
        $repeat = $this->placed->repeatPurchase($range, $placed['customers']);
        $previousRepeat = $this->placed->repeatPurchase($previousRange, $previousPlaced['customers']);

        $summary = $this->summary($placed, $series, $delivered, $repeat, $withProfit);
        $previous = $this->summary($previousPlaced, $previousSeries, $previousDelivered, $previousRepeat, $withProfit);

        return [
            'range' => $range->toArray(),
            'comparison' => $previousRange->toArray(),
            'granularity' => $granularity->value,
            'profit_visible' => $withProfit,
            'source' => $series['source'],
            'summary' => $summary,
            'previous' => $previous,
            'deltas' => [
                'placed_value' => Rates::moneyDelta($summary['placed']['net_value'], $previous['placed']['net_value']),
                'placed_orders' => Rates::countDelta($summary['placed']['net_orders'], $previous['placed']['net_orders']),
                'aov' => $summary['placed']['aov'] === null || $previous['placed']['aov'] === null ? null : Rates::moneyDelta($summary['placed']['aov'], $previous['placed']['aov']),
                'recognised_revenue' => Rates::moneyDelta($summary['recognised']['net_revenue'], $previous['recognised']['net_revenue']),
                'repeat_rate_points' => Rates::points($summary['customers']['repeat_rate'], $previous['customers']['repeat_rate']),
            ],
            'trend' => $this->trend($series, $placed, $previousPlaced, $delivered, $withProfit),
            'categories' => self::gate($lines['categories'], $withProfit),
            'brands' => self::gate($lines['brands'], $withProfit),
            'lines_total' => self::gate([$lines['totals']], $withProfit)[0],
            'reconciliation' => [
                // Ledger product revenue after discounts (sale_revenue + discount_given) vs the
                // delivered lines' net revenue. Equal unless a return or refund is dated in a
                // different period from the delivery it reverses (the ledger dates the reversal).
                'ledger_product_revenue' => $summary['recognised']['product_revenue']->plus($summary['recognised']['discounts']),
                'lines_net_revenue' => $lines['totals']['net_revenue'],
                'difference' => $summary['recognised']['product_revenue']->plus($summary['recognised']['discounts'])->minus($lines['totals']['net_revenue']),
            ],
            'payment_methods' => self::mix($placed['payment_methods'], $placed['totals'], 'method'),
            'channels' => self::mix($placed['channels'], $placed['totals'], 'source'),
            'discounts' => $this->discounts($range, $placed, $summary),
            'recommendations' => self::recommendations($this->placed->recommendationLines($range), $lines['sources'], $withProfit),
            'conversion' => ['available' => false, 'reason' => 'no_session_denominator'],
        ];
    }

    /**
     * Orders delivered in the range (Dhaka date of delivered_at) — count and order value,
     * per bucket when a granularity is given.
     *
     * @return array{orders: int, value: Money, buckets: array<string, int>}
     */
    private function delivered(DateRange $range, ?ReportGranularity $granularity): array
    {
        [$start, $end] = $range->utcBounds();
        $result = ['orders' => 0, 'value' => Money::zero(), 'buckets' => []];
        $bucketOf = $granularity === null ? null : PlacedOrders::bucketer($range, $granularity);
        $rows = DB::table('orders')
            ->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', $start)
            ->where('delivered_at', '<', $end)
            ->whereIn('status', Profitability::DELIVERED_STATUSES)
            ->orderBy('id')
            ->select(['id', 'delivered_at', 'grand_total'])
            ->cursor();
        foreach ($rows as $row) {
            $result['orders']++;
            $result['value'] = $result['value']->plus(ReportMoney::of($row->grand_total));
            if ($bucketOf !== null && is_scalar($row->delivered_at)) {
                $key = $bucketOf((string) $row->delivered_at);
                $result['buckets'][$key] = ($result['buckets'][$key] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $placed
     * @param  array<string, mixed>  $series
     * @param  array{orders: int, value: Money, buckets: array<string, int>}  $delivered
     * @param  array{customers: int, returning: int, new: int, repeat_rate: string|null}  $repeat
     * @return array<string, mixed>
     */
    private function summary(array $placed, array $series, array $delivered, array $repeat, bool $withProfit): array
    {
        $lines = ['product_revenue' => Money::zero(), 'delivery_revenue' => Money::zero(), 'discounts' => Money::zero(), 'cogs' => Money::zero()];
        foreach ($series['buckets'] as $bucket) {
            foreach (array_keys($lines) as $key) {
                $lines[$key] = $lines[$key]->plus($bucket['lines'][$key] ?? Money::zero());
            }
        }
        /** @var array<string, Money> $totals */
        $totals = $series['totals'];
        $t = $placed['totals'];

        return [
            'placed' => [
                'orders' => $t['orders'],
                'value' => $t['value'],
                'cancelled_orders' => $t['cancelled_orders'],
                'cancelled_value' => $t['cancelled_value'],
                'net_orders' => $t['net_orders'],
                'net_value' => $t['net_value'],
                'units' => $t['units'],
                'aov' => Rates::average($t['net_value'], $t['net_orders']),
                'cancel_rate' => Rates::percent($t['cancelled_orders'], $t['orders']),
            ],
            'delivered' => [
                'orders' => $delivered['orders'],
                'value' => $delivered['value'],
                'aov' => Rates::average($delivered['value'], $delivered['orders']),
            ],
            'recognised' => [
                'product_revenue' => $lines['product_revenue'],
                'delivery_revenue' => $lines['delivery_revenue'],
                'gross_revenue' => $totals['gross_revenue'],
                'discounts' => $lines['discounts'],
                'net_revenue' => $totals['net_revenue'],
                'cogs' => $withProfit ? $lines['cogs'] : null,
                'gross_profit' => $withProfit ? $totals['gross_profit'] : null,
                'gross_margin_percent' => $withProfit ? ReportMoney::percent($totals['gross_profit'], $totals['net_revenue']) : null,
            ],
            'customers' => $repeat,
        ];
    }

    /**
     * @param  array<string, mixed>  $series
     * @param  array<string, mixed>  $placed
     * @param  array<string, mixed>  $previousPlaced
     * @param  array{orders: int, value: Money, buckets: array<string, int>}  $delivered
     * @return list<array<string, mixed>>
     */
    private function trend(array $series, array $placed, array $previousPlaced, array $delivered, bool $withProfit): array
    {
        $previous = array_values($previousPlaced['buckets']);
        $rows = [];
        foreach ($series['buckets'] as $index => $bucket) {
            $p = $placed['buckets'][$bucket['key']] ?? null;
            $before = $previous[$index] ?? null;
            $rows[] = [
                'key' => $bucket['key'],
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'days' => $bucket['days'],
                'placed_orders' => $p['orders'] ?? 0,
                'placed_value' => $p['value'] ?? Money::zero(),
                'units' => $p['units'] ?? 0,
                'cancelled_orders' => $p['cancelled'] ?? 0,
                'outcomes' => $p['outcomes'] ?? array_fill_keys(array_keys(PlacedOrders::OUTCOMES), 0),
                'delivered_orders' => $delivered['buckets'][$bucket['key']] ?? 0,
                'recognised_revenue' => $bucket['totals']['net_revenue'],
                'gross_profit' => $withProfit ? $bucket['totals']['gross_profit'] : null,
                'previous_placed_orders' => $before['orders'] ?? null,
                'previous_placed_value' => $before['value'] ?? null,
                'previous_units' => $before['units'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, array{orders: int, value: Money}>  $groups
     * @param  array{net_orders: int, net_value: Money}  $totals
     * @return list<array<string, mixed>>
     */
    private static function mix(array $groups, array $totals, string $keyName): array
    {
        $rows = [];
        foreach ($groups as $key => $group) {
            $rows[] = [
                $keyName => (string) $key,
                'orders' => $group['orders'],
                'value' => $group['value'],
                'orders_percent' => Rates::percent($group['orders'], $totals['net_orders']),
                'value_percent' => Rates::moneyPercent($group['value'], $totals['net_value']),
                'aov' => Rates::average($group['value'], $group['orders']),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['orders'], $a[$keyName]] <=> [$a['orders'], $b[$keyName]]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $placed
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function discounts(DateRange $range, array $placed, array $summary): array
    {
        $d = $placed['discounts'];
        $netOrders = $placed['totals']['net_orders'];
        $coupons = [];
        foreach ($d['coupons'] as $code => $row) {
            $coupons[] = ['code' => (string) $code, 'orders' => $row['orders'], 'discount' => $row['discount'], 'value' => $row['value']];
        }
        usort($coupons, static fn (array $a, array $b): int => $b['discount']->compareTo($a['discount']) ?: strcmp($a['code'], $b['code']));

        return [
            'orders_with_discount' => $d['orders_with_discount'],
            'orders_with_discount_percent' => Rates::percent($d['orders_with_discount'], $netOrders),
            'discount_total' => $d['discount_total'],
            'coupon_discount' => $d['coupon_discount'],
            'promotion_discount' => $d['promotion_discount'],
            'shipping_discount' => $d['shipping_discount'],
            'aov_with_discount' => Rates::average($d['value_with_discount'], $d['orders_with_discount']),
            'aov_without_discount' => Rates::average($d['value_without_discount'], $netOrders - $d['orders_with_discount']),
            'recognised_discounts' => $summary['recognised']['discounts'],
            'coupons' => array_slice($coupons, 0, 10),
            'promotions' => $this->placed->promotions($range),
        ];
    }

    /**
     * @param  array<string, array{lines: int, units: int, orders: int, value: Money}>  $placed
     * @param  array<string, array<string, mixed>>  $delivered
     * @return list<array<string, mixed>>
     */
    private static function recommendations(array $placed, array $delivered, bool $withProfit): array
    {
        $total = Money::zero();
        foreach ($placed as $row) {
            $total = $total->plus($row['value']);
        }

        $rows = [];
        foreach (array_unique([...array_keys($placed), ...array_keys($delivered)]) as $source) {
            $p = $placed[$source] ?? ['lines' => 0, 'units' => 0, 'orders' => 0, 'value' => Money::zero()];
            $d = $delivered[$source] ?? null;
            $rows[] = [
                'source' => (string) $source,
                'placed_lines' => $p['lines'],
                'placed_units' => $p['units'],
                'placed_orders' => $p['orders'],
                'placed_value' => $p['value'],
                'placed_value_percent' => Rates::moneyPercent($p['value'], $total),
                'delivered_units' => $d['units'] ?? 0,
                'net_revenue' => $d['net_revenue'] ?? Money::zero(),
                'gross_profit' => $withProfit ? ($d['gross_profit'] ?? Money::zero()) : null,
                'return_rate' => $d['return_rate'] ?? null,
            ];
        }
        // Attributed placements first (largest value first), then `none`.
        usort($rows, static fn (array $a, array $b): int => [$a['source'] === self::NO_SOURCE, $b['placed_value']->toPoisha(), $a['source']] <=> [$b['source'] === self::NO_SOURCE, $a['placed_value']->toPoisha(), $b['source']]);

        return $rows;
    }

    /**
     * Blanks cost and profit for a viewer without cost visibility.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function gate(array $rows, bool $withProfit): array
    {
        if ($withProfit) {
            return $rows;
        }

        return array_map(static fn (array $row): array => [...$row, 'cogs' => null, 'gross_profit' => null, 'margin_percent' => null], $rows);
    }
}
