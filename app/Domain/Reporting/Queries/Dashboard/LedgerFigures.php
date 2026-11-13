<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Dashboard;

use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\LedgerTotals;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Reporting\Support\DateRange;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's ledger figures, read through the finance reports so they are the P&L's own
 * numbers to the poisha — never re-derived from orders:
 *
 *   recognised revenue  P&L `net_revenue` (sale + delivery revenue − discounts, recognised on
 *                       delivery — rule 10), {@see ProfitAndLoss::series()}
 *   gross profit        P&L `gross_profit` (net revenue − COGS at the snapshot cost, rule 7)
 *   net profit          P&L `net_profit`
 *   cash collected      Σ `cod_received` cash movements (in − out) — the courier remittance,
 *                       the cash view, kept apart from revenue (rule 10). Its total is
 *                       {@see LedgerTotals::cashByType()}; the per-bucket split is one grouped
 *                       read of the same rows on the `(occurred_on, type)` index.
 *
 * `occurred_on` is already a Dhaka date (the ledger writer converts), so buckets are Dhaka days.
 */
final readonly class LedgerFigures
{
    public function __construct(private ProfitAndLoss $pnl, private LedgerTotals $ledger) {}

    /**
     * @return array{
     *     totals: array{net_revenue: Money, gross_profit: Money, net_profit: Money, cash_collected: Money},
     *     gross_margin_percent: string|null,
     *     net_margin_percent: string|null,
     *     buckets: list<array{key: string, net_revenue: Money, gross_profit: Money, net_profit: Money, cash_collected: Money}>,
     *     source: string
     * }
     */
    public function figures(DateRange $range, ReportGranularity $granularity): array
    {
        $series = $this->pnl->series($range->period(), $granularity);
        $cash = $this->cashByBucket($range, $granularity);

        $buckets = [];
        foreach ($series['buckets'] as $bucket) {
            $buckets[] = [
                'key' => (string) $bucket['key'],
                'net_revenue' => $bucket['totals']['net_revenue'],
                'gross_profit' => $bucket['totals']['gross_profit'],
                'net_profit' => $bucket['totals']['net_profit'],
                'cash_collected' => $cash[$bucket['key']] ?? Money::zero(),
            ];
        }

        $flows = $this->ledger->cashByType($range->period())[LedgerEntryType::CodReceived->value] ?? ['in' => Money::zero(), 'out' => Money::zero()];
        $totals = $series['totals'];

        return [
            'totals' => [
                'net_revenue' => $totals['net_revenue'],
                'gross_profit' => $totals['gross_profit'],
                'net_profit' => $totals['net_profit'],
                'cash_collected' => $flows['in']->minus($flows['out']),
            ],
            'gross_margin_percent' => ReportMoney::percent($totals['gross_profit'], $totals['net_revenue']),
            'net_margin_percent' => ReportMoney::percent($totals['net_profit'], $totals['net_revenue']),
            'buckets' => $buckets,
            'source' => (string) $series['source'],
        ];
    }

    /** @return array<string, Money> bucket key => net COD cash received */
    private function cashByBucket(DateRange $range, ReportGranularity $granularity): array
    {
        $rows = DB::table('financial_transactions')
            ->where('occurred_on', '>=', $range->fromDate())
            ->where('occurred_on', '<=', $range->toDate())
            ->where('type', LedgerEntryType::CodReceived->value)
            ->where('is_cash_movement', true)
            ->groupBy('occurred_on', 'direction')
            ->select(['occurred_on', 'direction'])
            ->selectRaw('SUM(amount) as total')
            ->get();

        $buckets = [];
        foreach ($rows as $row) {
            $key = $range->bucketOf(substr((string) $row->occurred_on, 0, 10), $granularity);
            $amount = ReportMoney::of($row->total);
            $buckets[$key] = ($buckets[$key] ?? Money::zero())->plus($row->direction === 'in' ? $amount : $amount->negate());
        }

        return $buckets;
    }
}
