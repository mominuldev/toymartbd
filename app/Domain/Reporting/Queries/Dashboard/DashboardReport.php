<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Reporting\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The admin dashboard (roadmap Phase 8; 04-admin-spec B1; Figma B · 01 Dashboard 241:29848).
 * Read-only: it never writes to the ledger or to stock.
 *
 * Every figure is computed here (rule 1) and labelled for what it is:
 *   - "placed" figures are demand — orders placed in the range, by Dhaka day of placed_at;
 *   - "recognised" figures are the P&L's, from the ledger, on delivery (rule 10);
 *   - "cash collected" is COD remittance, the cash view (rule 10).
 *
 * Each KPI carries the same figure for the comparison range ({@see DateRange::previous()}) and
 * the change as a percent of |previous| (null when the previous figure is zero), plus a
 * sparkline in the range's buckets: a day up to 62 days, a Saturday–Friday week up to 366,
 * otherwise a month.
 */
final readonly class DashboardReport
{
    public function __construct(
        private PlacedOrders $placed,
        private LedgerFigures $ledger,
        private CategoryRevenue $categories,
        private LowStock $lowStock,
        private WorkQueues $queues,
        private ActivityFeed $activity,
    ) {}

    public static function granularity(DateRange $range): ReportGranularity
    {
        return match (true) {
            $range->days() <= 62 => ReportGranularity::Day,
            $range->days() <= 366 => ReportGranularity::Week,
            default => ReportGranularity::Month,
        };
    }

    /** @return array<string, mixed> */
    public function build(DateRange $range, DashboardScope $scope): array
    {
        $previous = $range->previous();
        $granularity = self::granularity($range);

        $report = [
            'range' => [...$range->toArray(), 'granularity' => $granularity->value],
            'previous' => ['from' => $previous->fromDate(), 'to' => $previous->toDate(), 'days' => $previous->days()],
            'visibility' => ['orders' => $scope->orders, 'finance' => $scope->finance, 'customers' => $scope->customers, 'inventory' => $scope->inventory],
            'kpis' => [
                'orders_placed' => null,
                'average_order_value' => null,
                'recognised_revenue' => null,
                'gross_profit' => null,
                'net_profit' => null,
                'cash_collected' => null,
                'customers' => null,
                'low_stock' => null,
            ],
            'status' => null,
            'trend' => null,
            'categories' => null,
            'best_sellers' => null,
            'low_stock' => null,
            'attention' => [],
            'activity' => null,
        ];

        $placed = $scope->orders || $scope->customers;
        $now = $placed ? $this->placed->scan($range, $granularity) : null;
        $before = $placed ? $this->placed->scan($previous, $granularity) : null;

        if ($scope->orders && $now !== null && $before !== null) {
            $report['kpis']['orders_placed'] = [
                'count' => $now['count'],
                'previous_count' => $before['count'],
                'count_delta_percent' => self::countDelta($now['count'], $before['count']),
                'value' => $now['value'],
                'previous_value' => $before['value'],
                'value_delta_percent' => ReportMoney::delta($now['value'], $before['value']),
                'sparkline' => array_map(static fn (array $b): array => ['key' => $b['key'], 'count' => $b['count'], 'value' => $b['value']], $now['buckets']),
            ];
            $report['kpis']['average_order_value'] = [
                'value' => $now['aov'],
                'previous' => $before['aov'],
                'delta_percent' => $now['aov'] !== null && $before['aov'] !== null ? ReportMoney::delta($now['aov'], $before['aov']) : null,
                'sparkline' => array_map(static fn (array $b): array => ['key' => $b['key'], 'value' => $b['aov']], $now['buckets']),
            ];
            $report['status'] = [
                'counts' => $this->queues->statusCounts(),
                'open' => $this->openOrders(),
            ];
            $report['best_sellers'] = $this->placed->bestSellers($range);
            $report['activity'] = $this->activity->latest();
        }

        if ($scope->customers && $now !== null && $before !== null) {
            $customers = $this->placed->customers($range, $granularity, $now['customer_ids']);
            $earlier = $this->placed->customers($previous, $granularity, $before['customer_ids']);
            $report['kpis']['customers'] = [
                'customers' => count($now['customer_ids']),
                'new' => $customers['new'],
                'returning' => $customers['returning'],
                'previous_customers' => count($before['customer_ids']),
                'previous_new' => $earlier['new'],
                'previous_returning' => $earlier['returning'],
                'new_delta_percent' => self::countDelta($customers['new'], $earlier['new']),
                'sparkline' => array_map(static fn (string $key, int $count): array => ['key' => $key, 'value' => $count], array_keys($customers['new_by_bucket']), array_values($customers['new_by_bucket'])),
            ];
        }

        $ledger = null;
        if ($scope->finance) {
            $ledger = $this->ledger->figures($range, $granularity);
            $earlier = $this->ledger->figures($previous, $granularity);
            foreach (['recognised_revenue' => 'net_revenue', 'gross_profit' => 'gross_profit', 'net_profit' => 'net_profit', 'cash_collected' => 'cash_collected'] as $kpi => $figure) {
                $report['kpis'][$kpi] = [
                    'value' => $ledger['totals'][$figure],
                    'previous' => $earlier['totals'][$figure],
                    'delta_percent' => ReportMoney::delta($ledger['totals'][$figure], $earlier['totals'][$figure]),
                    'sparkline' => array_map(static fn (array $b): array => ['key' => $b['key'], 'value' => $b[$figure]], $ledger['buckets']),
                ];
            }
            $report['kpis']['gross_profit']['margin_percent'] = $ledger['gross_margin_percent'];
            $report['kpis']['net_profit']['margin_percent'] = $ledger['net_margin_percent'];
            $report['kpis']['recognised_revenue']['source'] = $ledger['source'];
            $report['categories'] = $this->categories->slices($range);
        }

        if ($scope->orders && $now !== null && $before !== null) {
            $report['trend'] = $this->trend($granularity, $now['buckets'], $before['buckets'], $ledger);
        }

        if ($scope->inventory) {
            $low = $this->lowStock->summary();
            $report['low_stock'] = $low;
            $report['kpis']['low_stock'] = $low === null ? null : ['low' => $low['low'], 'out' => $low['out']];
        }

        $attention = $this->queues->attention($scope->attention);
        foreach ($attention as $key => $queue) {
            $report['attention'][] = ['key' => $key, ...$queue];
        }
        if ($scope->inventory && $report['kpis']['low_stock'] !== null) {
            $report['attention'][] = ['key' => 'low_stock', 'count' => $report['kpis']['low_stock']['low'] + $report['kpis']['low_stock']['out'], 'oldest_at' => null];
        }

        return $report;
    }

    /**
     * Placed value per bucket against the comparison range's buckets (aligned by position: day
     * 1 against day 1), and recognised revenue per bucket when the viewer may see it.
     *
     * @param  list<array{key: string, from: string, to: string, count: int, value: Money, units: int}>  $now
     * @param  list<array{key: string, from: string, to: string, count: int, value: Money, units: int}>  $before
     * @param  array{totals: array{net_revenue: Money}, buckets: list<array{key: string, net_revenue: Money}>}|null  $ledger
     * @return array<string, mixed>
     */
    private function trend(ReportGranularity $granularity, array $now, array $before, ?array $ledger): array
    {
        $revenue = [];
        foreach ($ledger['buckets'] ?? [] as $bucket) {
            $revenue[$bucket['key']] = $bucket['net_revenue'];
        }

        $buckets = [];
        $placed = Money::zero();
        $previous = Money::zero();
        foreach ($now as $index => $bucket) {
            $prior = $before[$index] ?? null;
            $placed = $placed->plus($bucket['value']);
            $buckets[] = [
                'key' => $bucket['key'],
                'from' => $bucket['from'],
                'to' => $bucket['to'],
                'placed_value' => $bucket['value'],
                'placed_count' => $bucket['count'],
                'units' => $bucket['units'],
                'previous_key' => $prior['key'] ?? null,
                'previous_placed_value' => $prior['value'] ?? null,
                'previous_placed_count' => $prior['count'] ?? null,
                'previous_units' => $prior['units'] ?? null,
                'recognised_revenue' => $ledger === null ? null : ($revenue[$bucket['key']] ?? Money::zero()),
            ];
        }
        foreach ($before as $bucket) {
            $previous = $previous->plus($bucket['value']);
        }

        return [
            'granularity' => $granularity->value,
            'placed_value' => $placed,
            'previous_placed_value' => $previous,
            'recognised_revenue' => $ledger === null ? null : $ledger['totals']['net_revenue'],
            'buckets' => $buckets,
        ];
    }

    /**
     * Placed and not yet delivered, cancelled or returned — the "not in revenue yet" note on
     * the revenue card. Same statuses as the P&L's "open orders" line.
     *
     * @return array{count: int, value: Money}
     */
    private function openOrders(): array
    {
        $row = DB::table('orders')->whereIn('status', ProfitAndLoss::OPEN_STATUSES)->selectRaw('COUNT(*) as orders, SUM(grand_total) as value')->first();

        return ['count' => (int) ($row->orders ?? 0), 'value' => ReportMoney::of($row->value ?? null)];
    }

    private static function countDelta(int $now, int $before): ?string
    {
        return ReportMoney::delta(Money::of($now), Money::of($before));
    }
}
