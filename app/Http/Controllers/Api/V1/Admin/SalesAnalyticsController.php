<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Reporting\Exports\ReportSheets;
use App\Domain\Reporting\Exports\Spreadsheet;
use App\Domain\Reporting\Queries\Sales\SalesAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\SalesAnalyticsResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Sales analytics (P8; Figma B · 24 Analytics 241:30584, Sales tab) and its CSV / XLSX
 * export. Read-only. reports.view; cost and profit need finance.view + catalog.view_cost;
 * the export also reports.export.
 */
final class SalesAnalyticsController extends ApiController
{
    /** The most buckets one trend returns. */
    private const int MAX_BUCKETS = 400;

    /** `GET /analytics/sales` */
    #[QueryParameter('range', 'Preset: today, 7d, 30d, this_month, last_month or custom (needs filter[from] and filter[to]). Default 7d, or custom when dates are given.', type: 'string', default: '7d')]
    #[QueryParameter('filter[from]', 'Custom range: first Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Custom range: last Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('group', 'Trend bucket: day, week (Saturday–Friday) or month. Default: day ≤ 31 days, week ≤ 186, else month.', type: "'day'|'week'|'month'")]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an unknown preset, a malformed or future date, or too many buckets (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view (`forbidden`)')]
    public function index(Request $request, SalesAnalytics $analytics): SalesAnalyticsResource
    {
        $this->authorize('view', SalesAnalytics::class);
        [$range, $granularity] = $this->query($request, []);

        return new SalesAnalyticsResource($analytics->report($range, $granularity, $this->profitVisible($request)));
    }

    /** `GET /analytics/sales/export` — every section, as CSV (sections one after another) or XLSX (one sheet each). */
    #[QueryParameter('format', 'csv or xlsx.', type: "'csv'|'xlsx'", default: 'csv')]
    #[QueryParameter('locale', 'Language of the labels and sheet names.', type: "'en'|'bn'", default: 'en')]
    #[QueryParameter('range', 'As on GET /analytics/sales.', type: 'string', default: '7d')]
    #[QueryParameter('filter[from]', 'As on GET /analytics/sales.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /analytics/sales.', type: 'string', format: 'date')]
    #[QueryParameter('group', 'As on GET /analytics/sales.', type: "'day'|'week'|'month'")]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, or an invalid value (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view or reports.export (`forbidden`)')]
    public function export(Request $request, SalesAnalytics $analytics): StreamedResponse
    {
        $this->authorize('export', SalesAnalytics::class);
        [$range, $granularity, $query] = $this->query($request, ['format', 'locale']);
        $format = $query->choice('format', Spreadsheet::formats(), Spreadsheet::CSV);
        $locale = $query->choice('locale', ['en', 'bn'], 'en');
        $report = $analytics->report($range, $granularity, $this->profitVisible($request));

        // Named for the Dhaka dates covered and the Dhaka day it was exported.
        return Spreadsheet::download(
            "sales-analytics-{$range->fromDate()}-to-{$range->toDate()}-exported-".DhakaDate::today(),
            $format,
            ReportSheets::sales($report, $locale),
        );
    }

    /**
     * @param  list<string>  $extra
     * @return array{0: DateRange, 1: ReportGranularity, 2: ReportQuery}
     */
    private function query(Request $request, array $extra): array
    {
        $query = ReportQuery::from($request, ['from', 'to'], ['range', 'group', ...$extra]);
        $from = $query->date('from');
        $to = $query->date('to');
        $preset = $query->choice('range', DateRange::presets(), $from !== null || $to !== null ? DateRange::CUSTOM : DateRange::DEFAULT_PRESET);

        try {
            $range = DateRange::fromPreset($preset, $from, $to);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $granularity = ReportGranularity::from($query->choice('group', SalesAnalytics::GRANULARITIES, SalesAnalytics::defaultGranularity($range)->value));
        if (count($range->buckets($granularity)) > self::MAX_BUCKETS) {
            throw new BadRequestHttpException('Too many buckets: at most '.self::MAX_BUCKETS.' per trend; group by a longer period.');
        }

        return [$range, $granularity, $query];
    }

    private function profitVisible(Request $request): bool
    {
        return $request->user()?->can('viewProfit', SalesAnalytics::class) ?? false;
    }
}
