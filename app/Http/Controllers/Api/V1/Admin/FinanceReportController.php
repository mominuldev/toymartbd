<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Reports\FinanceReport;
use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ProfitAndLoss;
use App\Domain\Inventory\Reports\InventoryValuationReport;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\PnlSeriesResource;
use App\Http\Resources\Admin\Reports\PnlStatementResource;
use App\Http\Resources\Admin\Reports\ProfitabilityReportResource;
use App\Support\CsvReport;
use App\Support\Money;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Generator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Finance reports (P5): the P&L statement, the P&L by day/week/month/year, and product /
 * category / recommendation-source profitability, each with a CSV export. Read-only.
 *
 * Every figure is computed here, on the server (rule 1); periods are Dhaka dates (rule 11);
 * revenue is recognised on delivery and cash is reported apart from it (rule 10).
 */
final class FinanceReportController extends ApiController
{
    private const array COMPARE = ['none', 'previous_period', 'previous_year'];

    private const array BY = ['product', 'category', 'source'];

    /**
     * `GET /finance/reports/pnl` — the five-block statement for a period. Needs finance.view.
     */
    #[QueryParameter('filter[from]', 'First Dhaka day, YYYY-MM-DD (inclusive). Default: 6 days before today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka day, YYYY-MM-DD (inclusive). Default: today.', type: 'string', format: 'date')]
    #[QueryParameter('compare', 'Comparison period for the deltas.', type: "'none'|'previous_period'|'previous_year'", default: 'previous_period')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, or an invalid date (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing finance.view (`forbidden`)')]
    public function pnl(Request $request, ProfitAndLoss $pnl, InventoryValuationReport $valuation): PnlStatementResource
    {
        $this->authorize('viewPnl', FinanceReport::class);
        $query = ReportQuery::from($request, ['from', 'to'], ['compare']);

        $statement = $pnl->statement($query->period(), $this->compare($query));
        $statement['inventory_at_cost'] = $request->user()?->can('viewValuation', FinanceReport::class)
            ? $valuation->report()['totals']['value']
            : null;

        return new PnlStatementResource($statement);
    }

    /**
     * `GET /finance/reports/pnl/series` — the P&L subtotals per day, week (Saturday start),
     * month or year. Needs finance.view.
     */
    #[QueryParameter('filter[from]', 'First Dhaka day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('group', 'Bucket size. Default: day up to 62 days, month up to 366, else year. At most 400 buckets.', type: "'day'|'week'|'month'|'year'")]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an invalid date, or too many buckets (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing finance.view (`forbidden`)')]
    public function series(Request $request, ProfitAndLoss $pnl): PnlSeriesResource
    {
        $this->authorize('viewPnl', FinanceReport::class);
        $query = ReportQuery::from($request, ['from', 'to'], ['group']);
        $period = $query->period();

        return new PnlSeriesResource($pnl->series($period, $query->granularity($period)));
    }

    /**
     * `GET /finance/reports/profitability` — profitability of the lines of orders delivered in
     * the period, by product, primary category or recommendation source. Needs finance.view
     * and catalog.view_cost.
     */
    #[QueryParameter('by', 'Grouping.', type: "'product'|'category'|'source'", default: 'product')]
    #[QueryParameter('filter[from]', 'First Dhaka delivery day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka delivery day, YYYY-MM-DD (inclusive).', type: 'string', format: 'date')]
    #[QueryParameter('filter[category_id]', 'Only products whose primary category is this one or below it.', type: 'int')]
    #[QueryParameter('filter[search]', 'Contains-match on the line\'s product name, name_bn or SKU.', type: 'string')]
    #[QueryParameter('filter[source]', 'Only lines with this recommendation_source; `none` = lines without one.', type: 'string')]
    #[QueryParameter('sort', 'net_revenue, gross_profit, margin_percent, units, cogs or name; prefix - for descending.', type: 'string', default: '-gross_profit')]
    #[OpenApiResponse(400, 'Unlisted parameter, filter or sort, or an invalid value (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing finance.view or catalog.view_cost (`forbidden`)')]
    public function profitability(Request $request, Profitability $profitability): ProfitabilityReportResource
    {
        $this->authorize('viewProfitability', FinanceReport::class);

        return new ProfitabilityReportResource($this->profitabilityReport($request, $profitability));
    }

    /** `GET /finance/reports/pnl/export` — the statement as CSV, one row per line. Needs finance.view + finance.export. */
    #[QueryParameter('filter[from]', 'As on GET /finance/reports/pnl.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /finance/reports/pnl.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing finance.view or finance.export (`forbidden`)')]
    public function exportPnl(Request $request, ProfitAndLoss $pnl): BinaryFileResponse
    {
        $this->authorize('exportPnl', FinanceReport::class);
        $period = ReportQuery::from($request, ['from', 'to'])->period();
        $statement = $pnl->statement($period, 'none');

        $rows = (static function () use ($statement): Generator {
            foreach ($statement['blocks'] as $block) {
                foreach ($block['rows'] as $row) {
                    yield [
                        (string) $block['number'],
                        __('reports.pnl.block.'.$block['key']),
                        $row['kind'],
                        $row['key'],
                        __('reports.pnl.line.'.$row['key']),
                        implode(' ', $row['types'] ?? []),
                        $row['amount']->toDecimal(),
                    ];
                }
            }
            foreach ($statement['expenses_by_category'] as $category) {
                yield ['', __('reports.pnl.expenses_by_category'), 'category', (string) $category['category_id'], $category['name'], $category['type'], $category['amount']->toDecimal()];
            }
            yield ['', __('reports.pnl.cash'), 'cash', 'cash_in', __('reports.pnl.cash_in'), '', $statement['cash']['in']->toDecimal()];
            yield ['', __('reports.pnl.cash'), 'cash', 'cash_out', __('reports.pnl.cash_out'), '', $statement['cash']['out']->negate()->toDecimal()];
            yield ['', __('reports.pnl.cash'), 'cash', 'cash_net', __('reports.pnl.cash_net'), '', $statement['cash']['net']->toDecimal()];
        })();

        return CsvReport::download(
            "profit-and-loss-{$period->fromDate()}-to-{$period->toDate()}.csv",
            ['block', 'block_name', 'kind', 'key', 'label', 'ledger_types', 'amount'],
            $rows,
        );
    }

    /** `GET /finance/reports/pnl/series/export` — the series as CSV, one row per bucket. Needs finance.view + finance.export. */
    #[QueryParameter('filter[from]', 'As on GET /finance/reports/pnl/series.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /finance/reports/pnl/series.', type: 'string', format: 'date')]
    #[QueryParameter('group', 'As on GET /finance/reports/pnl/series.', type: "'day'|'week'|'month'|'year'")]
    #[OpenApiResponse(400, 'Unlisted parameter or filter (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing finance.view or finance.export (`forbidden`)')]
    public function exportSeries(Request $request, ProfitAndLoss $pnl): BinaryFileResponse
    {
        $this->authorize('exportPnl', FinanceReport::class);
        $query = ReportQuery::from($request, ['from', 'to'], ['group']);
        $period = $query->period();
        $series = $pnl->series($period, $query->granularity($period));

        $lineKeys = array_keys($series['buckets'][0]['lines'] ?? []);
        $totalKeys = ['gross_revenue', 'net_revenue', 'gross_profit', 'profit_after_delivery', 'profit_after_losses', 'net_profit'];
        $rows = array_map(static fn (array $b): array => [
            $b['from'], $b['to'], (string) $b['days'],
            ...array_map(static fn (string $k): string => $b['lines'][$k]->toDecimal(), $lineKeys),
            ...array_map(static fn (string $k): string => $b['totals'][$k]->toDecimal(), $totalKeys),
            (string) ($b['net_margin_percent'] ?? ''),
        ], $series['buckets']);

        return CsvReport::download(
            "profit-and-loss-by-{$series['granularity']}-{$period->fromDate()}-to-{$period->toDate()}.csv",
            ['from', 'to', 'days', ...$lineKeys, ...$totalKeys, 'net_margin_percent'],
            $rows,
        );
    }

    /** `GET /finance/reports/profitability/export` — as GET /finance/reports/profitability, every row. Needs finance.export too. */
    #[QueryParameter('by', 'As on GET /finance/reports/profitability.', type: "'product'|'category'|'source'", default: 'product')]
    #[QueryParameter('filter[from]', 'As on GET /finance/reports/profitability.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /finance/reports/profitability.', type: 'string', format: 'date')]
    #[QueryParameter('filter[category_id]', 'As on GET /finance/reports/profitability.', type: 'int')]
    #[QueryParameter('filter[search]', 'As on GET /finance/reports/profitability.', type: 'string')]
    #[QueryParameter('filter[source]', 'As on GET /finance/reports/profitability.', type: 'string')]
    #[QueryParameter('sort', 'As on GET /finance/reports/profitability.', type: 'string', default: '-gross_profit')]
    #[OpenApiResponse(400, 'Unlisted parameter, filter or sort (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing finance.view, catalog.view_cost or finance.export (`forbidden`)')]
    public function exportProfitability(Request $request, Profitability $profitability): BinaryFileResponse
    {
        $this->authorize('exportProfitability', FinanceReport::class);
        $report = $this->profitabilityReport($request, $profitability);

        $identity = match ($report['by']) {
            'product' => ['product_id', 'sku', 'name', 'name_bn', 'category_name'],
            'category' => ['category_id', 'parent_id', 'name', 'name_bn', 'products'],
            default => ['source', 'products'],
        };
        $figures = ['orders', 'units_sold', 'units_returned', 'units', 'gross_revenue', 'reversals', 'net_revenue', 'cogs', 'gross_profit', 'margin_percent'];
        $cell = static fn (mixed $v): string => $v instanceof Money ? $v->toDecimal() : (is_scalar($v) ? (string) $v : '');

        return CsvReport::download(
            "profitability-by-{$report['by']}-{$report['from']}-to-{$report['to']}.csv",
            [...$identity, ...$figures],
            array_map(static fn (array $row): array => array_map(static fn (string $k): string => $cell($row[$k] ?? null), [...$identity, ...$figures]), $report['rows']),
        );
    }

    /** @return array<string, mixed> */
    private function profitabilityReport(Request $request, Profitability $profitability): array
    {
        $query = ReportQuery::from($request, ['from', 'to', 'category_id', 'search', 'source'], ['by', 'sort']);
        $period = $query->period();
        $by = $query->choice('by', self::BY, 'product');
        $sort = $query->sort(Profitability::SORTS, '-gross_profit');
        $filters = [
            'category_id' => $query->id('category_id'),
            'search' => $query->search(),
            'source' => $query->filterIn('source'),
        ];

        $report = match ($by) {
            'category' => $profitability->byCategory($period, $filters, $sort),
            'source' => $profitability->bySource($period, $filters, $sort),
            default => $profitability->byProduct($period, $filters, $sort),
        };

        return [...$report, 'by' => $by, 'sort' => $sort, 'from' => $period->fromDate(), 'to' => $period->toDate()];
    }

    /** @return 'none'|'previous_period'|'previous_year' */
    private function compare(ReportQuery $query): string
    {
        /** @var 'none'|'previous_period'|'previous_year' */
        return $query->choice('compare', self::COMPARE, 'previous_period');
    }
}
