<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\Queries\Products\ProductAnalytics;
use App\Domain\Reporting\Queries\Sales\SalesAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\ProductAnalyticsResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Product analytics (P10; Figma B · 24 Analytics, Products tab 522:80395). Read-only.
 * Authorised like the sales analytics it sits beside — the SalesAnalytics policy subject:
 * reports.view; cost, profit, margin and stock value need finance.view + catalog.view_cost.
 */
final class ProductAnalyticsController extends ApiController
{
    /** `GET /analytics/products` */
    #[QueryParameter('range', 'Preset: today, 7d, 30d, this_month, last_month or custom (needs filter[from] and filter[to]). Default 30d, or custom when dates are given.', type: 'string', default: '30d')]
    #[QueryParameter('filter[from]', 'Custom range: first Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Custom range: last Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('sort', 'Best-seller order: revenue, units, margin (revenue when cost is hidden) or returns.', type: "'revenue'|'units'|'margin'|'returns'", default: 'revenue')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an unknown preset or sort, a malformed or future date (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view (`forbidden`)')]
    public function index(Request $request, ProductAnalytics $analytics): ProductAnalyticsResource
    {
        $this->authorize('view', SalesAnalytics::class);

        $query = ReportQuery::from($request, ['from', 'to'], ['range', 'sort']);
        $from = $query->date('from');
        $to = $query->date('to');
        $preset = $query->choice('range', DateRange::presets(), $from !== null || $to !== null ? DateRange::CUSTOM : DateRange::LAST_30_DAYS);
        $sort = $query->choice('sort', ProductAnalytics::SORTS, ProductAnalytics::DEFAULT_SORT);

        try {
            $range = DateRange::fromPreset($preset, $from, $to);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $profitVisible = $request->user()?->can('viewProfit', SalesAnalytics::class) ?? false;

        return new ProductAnalyticsResource($analytics->report($range, $profitVisible, $sort));
    }
}
