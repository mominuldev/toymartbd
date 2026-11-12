<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Support\Money;
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

        return [
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
            'days' => $period->days(),
            'blocks' => $statement['blocks'],
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
                'total' => $split['orders']->plus($split['other']),
                'reconciled' => $split['orders']->plus($split['other'])->equals($statement['totals']['net_profit']),
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
     * "Not in this statement": orders placed and not yet delivered, at their order value
     * — what a reader might wrongly assume the profit includes. Current, not per period.
     *
     * @return array{count: int, value: Money}
     */
    private function openOrders(): array
    {
        $row = DB::table('orders')
            ->whereIn('status', self::OPEN_STATUSES)
            ->selectRaw('COUNT(*) as orders, SUM(grand_total) as value')
            ->first();

        return ['count' => (int) ($row->orders ?? 0), 'value' => ReportMoney::of($row->value ?? null)];
    }
}
