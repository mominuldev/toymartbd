<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The profit & loss statement and its day/week/month/year series (P5, 04-admin-spec A19).
 *
 * Accrual basis: only entries flagged `is_recognised_revenue` (rule 10) — revenue is
 * recognised on delivery, so an order in a courier's van is not in it. Cash is reported
 * separately from `is_cash_movement` entries and never mixed into a P&L line.
 *
 * Read-only. Short ranges read the ledger; long ranges read the nightly
 * `daily_financial_summaries` rollup for the days it covers (see DailyTotals).
 */
final readonly class ProfitAndLoss
{
    /** Order statuses still in the pipeline (placed, not yet delivered, cancelled or returned). */
    public const array OPEN_STATUSES = ['pending', 'on_hold', 'confirmed', 'processing', 'ready_to_ship', 'shipped'];

    public function __construct(private LedgerTotals $ledger, private DailyTotals $daily) {}

    /**
     * @param  'none'|'previous_period'|'previous_year'  $compare
     * @return array<string, mixed>
     */
    public function statement(ReportPeriod $period, string $compare = 'previous_period'): array
    {
        $byType = $this->daily->byType($period);
        $statement = self::layout($byType);
        $previous = match ($compare) {
            'previous_period' => $period->previous(),
            'previous_year' => $period->previousYear(),
            default => null,
        };

        $comparison = null;
        if ($previous !== null) {
            $before = self::totals($this->daily->byType($previous));
            $comparison = [
                'kind' => $compare,
                'from' => $previous->fromDate(),
                'to' => $previous->toDate(),
                'totals' => $before,
                'deltas' => array_combine(
                    PnlLayout::SUBTOTALS,
                    array_map(static fn (string $k): ?string => ReportMoney::delta($statement['totals'][$k], $before[$k]), PnlLayout::SUBTOTALS),
                ),
            ];
        }

        $cash = $this->ledger->cashByType($period);
        $cashIn = Money::zero();
        $cashOut = Money::zero();
        foreach ($cash as $flows) {
            $cashIn = $cashIn->plus($flows['in']);
            $cashOut = $cashOut->plus($flows['out']);
        }

        $split = $this->ledger->orderSplit($period);
        $blocks = self::withShares($statement['blocks'], $statement['totals']['net_revenue'], $this->ledger->recognisedOccurrences($period));
        $ledgerNet = $split['orders']->plus($split['other']);

        return [
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
            'days' => $period->days(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'blocks' => $blocks,
            'biggest_costs' => self::biggestCosts($blocks),
            'totals' => $statement['totals'],
            'gross_margin_percent' => ReportMoney::percent($statement['totals']['gross_profit'], $statement['totals']['net_revenue']),
            'net_margin_percent' => ReportMoney::percent($statement['totals']['net_profit'], $statement['totals']['net_revenue']),
            'unmapped' => $statement['unmapped'],
            'expenses_by_category' => $this->ledger->recognisedByCategory($period),
            'cash' => [
                'in' => $cashIn,
                'out' => $cashOut,
                'net' => $cashIn->minus($cashOut),
                'by_type' => array_map(
                    static fn (string $type, array $flows): array => ['type' => $type, 'in' => $flows['in'], 'out' => $flows['out']],
                    array_keys($cash),
                    array_values($cash),
                ),
            ],
            'reconciliation' => [
                'orders' => $split['orders'],
                'order_count' => $split['order_count'],
                'other' => $split['other'],
                'total' => $ledgerNet,
                // Statement minus the order-by-order rebuild: zero when it ties up.
                'difference' => $statement['totals']['net_profit']->minus($ledgerNet),
                'reconciled' => $ledgerNet->equals($statement['totals']['net_profit']),
            ],
            'open_orders' => $this->openOrders(),
            'comparison' => $comparison,
            'source' => $this->daily->sourceFor($period),
        ];
    }

    /**
     * One bucket per day / week (Saturday start) / month / year, each with the statement's
     * subtotals and its per-line amounts.
     *
     * @return array{granularity: string, from: string, to: string, source: string, buckets: list<array<string, mixed>>, totals: array<string, Money>}
     */
    public function series(ReportPeriod $period, ReportGranularity $granularity): array
    {
        $byDay = $this->daily->byDay($period);

        /** @var array<string, array<string, Money>> $grouped */
        $grouped = [];
        foreach ($byDay as $day => $types) {
            $key = $period->bucketOf($day, $granularity);
            foreach ($types as $type => $amount) {
                $grouped[$key][$type] = ($grouped[$key][$type] ?? Money::zero())->plus($amount);
            }
        }

        $buckets = [];
        $all = [];
        foreach ($period->buckets($granularity) as $bucket) {
            $types = $grouped[$bucket['key']] ?? [];
            foreach ($types as $type => $amount) {
                $all[$type] = ($all[$type] ?? Money::zero())->plus($amount);
            }
            $layout = self::layout($types);
            $buckets[] = [
                ...$bucket,
                'totals' => $layout['totals'],
                'lines' => self::lineAmounts($layout['blocks']),
                'net_margin_percent' => ReportMoney::percent($layout['totals']['net_profit'], $layout['totals']['net_revenue']),
            ];
        }

        return [
            'granularity' => $granularity->value,
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
            'source' => $this->daily->sourceFor($period),
            'buckets' => $buckets,
            'totals' => self::totals($all),
        ];
    }

    /**
     * Lays per-type nets into the five blocks with running subtotals.
     *
     * @param  array<string, Money>  $byType
     * @return array{blocks: list<array<string, mixed>>, totals: array<string, Money>, unmapped: list<array{type: string, amount: Money}>}
     */
    public static function layout(array $byType): array
    {
        $running = Money::zero();
        $totals = [];
        $blocks = [];

        foreach (PnlLayout::blocks() as $block) {
            $rows = [];
            foreach ($block['rows'] as $row) {
                if ($row['kind'] === 'subtotal') {
                    $totals[$row['key']] = $running;
                    $rows[] = ['kind' => 'subtotal', 'key' => $row['key'], 'amount' => $running];

                    continue;
                }
                $amount = Money::zero();
                foreach ($row['types'] as $type) {
                    $amount = $amount->plus($byType[$type->value] ?? Money::zero());
                }
                $running = $running->plus($amount);
                $rows[] = ['kind' => 'line', 'key' => $row['key'], 'types' => array_map(static fn ($t): string => $t->value, $row['types']), 'amount' => $amount];
            }
            $blocks[] = ['key' => $block['key'], 'number' => $block['number'], 'rows' => $rows];
        }

        $unmapped = [];
        foreach (array_diff(array_keys($byType), PnlLayout::pnlTypeValues()) as $type) {
            if (! $byType[$type]->isZero()) {
                $unmapped[] = ['type' => (string) $type, 'amount' => $byType[$type]];
            }
        }

        return ['blocks' => $blocks, 'totals' => $totals, 'unmapped' => $unmapped];
    }

    /**
     * @param  array<string, Money>  $byType
     * @return array<string, Money>
     */
    public static function totals(array $byType): array
    {
        return self::layout($byType)['totals'];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, Money>
     */
    private static function lineAmounts(array $blocks): array
    {
        $lines = [];
        foreach ($blocks as $block) {
            foreach ($block['rows'] as $row) {
                if ($row['kind'] === 'line') {
                    $lines[$row['key']] = $row['amount'];
                }
            }
        }

        return $lines;
    }

    /**
     * Subtotals from which cost of goods sold can be backed out with one subtraction
     * (net revenue − gross profit, or a later subtotal plus the visible lines between).
     */
    public const array COST_REVEALING_TOTALS = ['gross_profit', 'profit_after_delivery', 'profit_after_losses'];

    /**
     * The statement for a reader without catalog.view_cost (owner decision 2026-11-16, B · 31
     * premium state A): the cost-of-goods line, block 2's total, the gross profit / profit after
     * delivery / profit after losses subtotals with their shares, the gross margin, and the
     * same figures of the comparison are withheld (null — never in the JSON). Net revenue, the
     * other lines, net profit and cash stay.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>
     */
    public static function withoutItemCosts(array $statement): array
    {
        foreach ($statement['blocks'] as $b => $block) {
            foreach ($block['rows'] as $r => $row) {
                $hidden = ($row['kind'] === 'line' && $block['key'] === 'goods')
                    || ($row['kind'] === 'subtotal' && in_array($row['key'], self::COST_REVEALING_TOTALS, true));
                if (! $hidden) {
                    continue;
                }
                $statement['blocks'][$b]['rows'][$r] = [...$row, 'amount' => null, 'percent_of_revenue' => null];
                if ($row['kind'] === 'line') {
                    $statement['blocks'][$b]['rows'][$r]['occurrences'] = null;
                }
            }
            if ($block['key'] === 'goods') {
                $statement['blocks'][$b]['total'] = null;
                $statement['blocks'][$b]['percent_of_revenue'] = null;
            }
        }
        foreach (self::COST_REVEALING_TOTALS as $key) {
            $statement['totals'][$key] = null;
            if (is_array($statement['comparison'] ?? null)) {
                $statement['comparison']['totals'][$key] = null;
                $statement['comparison']['deltas'][$key] = null;
            }
        }
        $statement['gross_margin_percent'] = null;
        $statement['biggest_costs'] = array_values(array_filter($statement['biggest_costs'] ?? [], static fn (array $c): bool => $c['block'] !== 'goods'));

        return $statement;
    }

    /**
     * The day/week/month/year series under the same rule: each bucket's and the overall
     * cost-of-goods line and cost-revealing subtotals are null.
     *
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>
     */
    public static function seriesWithoutItemCosts(array $series): array
    {
        foreach ($series['buckets'] as $i => $bucket) {
            foreach (self::COST_REVEALING_TOTALS as $key) {
                $series['buckets'][$i]['totals'][$key] = null;
            }
            $series['buckets'][$i]['lines']['cogs'] = null;
        }
        foreach (self::COST_REVEALING_TOTALS as $key) {
            $series['totals'][$key] = null;
        }

        return $series;
    }

    /**
     * Adds what the premium statement draws next to each figure (B · 31 premium): every row's
     * and every block's share of net revenue (signed, 2 dp, null without revenue), each
     * block's total (the sum of its lines — the step down in the "revenue → net profit"
     * journey), and each line's occurrence count for its caption.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, int>  $occurrences
     * @return list<array<string, mixed>>
     */
    private static function withShares(array $blocks, Money $netRevenue, array $occurrences): array
    {
        foreach ($blocks as $b => $block) {
            $total = Money::zero();
            foreach ($block['rows'] as $r => $row) {
                $blocks[$b]['rows'][$r]['percent_of_revenue'] = ReportMoney::percent($row['amount'], $netRevenue);
                if ($row['kind'] === 'line') {
                    $total = $total->plus($row['amount']);
                    $blocks[$b]['rows'][$r]['occurrences'] = array_sum(array_map(static fn (string $t): int => $occurrences[$t] ?? 0, $row['types']));
                }
            }
            $blocks[$b]['total'] = $total;
            $blocks[$b]['percent_of_revenue'] = ReportMoney::percent($total, $netRevenue);
        }

        return $blocks;
    }

    /**
     * "Biggest costs after the toys": the four lines in blocks 3–5 that took the most off
     * profit, as positive costs, each with its size relative to the largest (100 = largest).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array{key: string, block: string, cost: Money, relative_percent: string}>
     */
    private static function biggestCosts(array $blocks): array
    {
        $costs = [];
        foreach ($blocks as $block) {
            // Revenue-side reductions (discounts) are not costs; block 2 is "the toys" itself.
            if ($block['key'] === 'goods' || $block['key'] === 'revenue') {
                continue;
            }
            foreach ($block['rows'] as $row) {
                if ($row['kind'] === 'line' && $row['amount']->isNegative()) {
                    $costs[] = ['key' => $row['key'], 'block' => $block['key'], 'cost' => $row['amount']->negate()];
                }
            }
        }
        usort($costs, static fn (array $a, array $b): int => $b['cost']->compareTo($a['cost']));
        $costs = array_slice($costs, 0, 4);
        $largest = $costs[0]['cost'] ?? Money::zero();

        return array_map(static fn (array $c): array => [...$c, 'relative_percent' => ReportMoney::percent($c['cost'], $largest) ?? '0.00'], $costs);
    }

    /**
     * "Not in this statement": orders placed and not yet delivered, at their order value
     * — what a reader might wrongly assume the profit includes. Current, not per period.
     *
     * @return array{count: int, value: Money, by_status: list<array{status: string, count: int, value: Money}>}
     */
    private function openOrders(): array
    {
        $rows = DB::table('orders')
            ->whereIn('status', self::OPEN_STATUSES)
            ->groupBy('status')
            ->select('status')
            ->selectRaw('COUNT(*) as orders, SUM(grand_total) as value')
            ->get();

        $count = 0;
        $value = Money::zero();
        $byStatus = [];
        foreach (self::OPEN_STATUSES as $status) {
            $row = $rows->firstWhere('status', $status);
            if ($row === null) {
                continue;
            }
            $amount = ReportMoney::of($row->value ?? null);
            $count += (int) $row->orders;
            $value = $value->plus($amount);
            $byStatus[] = ['status' => $status, 'count' => (int) $row->orders, 'value' => $amount];
        }

        return ['count' => $count, 'value' => $value, 'by_status' => $byStatus];
    }
}
