<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\Queries\Sales\SalesAnalytics;
use App\Domain\Reporting\Queries\Visitors\VisitorAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\VisitorAnalyticsResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Analytics › Visitors & funnel (Figma B · 24 premium pass 522:79774). Read-only; reports.view,
 * through the sales analytics policy (the same permission guards every analytics tab).
 */
final class VisitorAnalyticsController extends ApiController
{
    /** The longest range one request may cover (one daily row per day). */
    private const int MAX_DAYS = 400;

    /** `GET /analytics/visitors` */
    #[QueryParameter('range', 'Preset: today, 7d, 30d, this_month, last_month or custom (needs filter[from] and filter[to]). Default 7d, or custom when dates are given.', type: 'string', default: '7d')]
    #[QueryParameter('filter[from]', 'Custom range: first Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Custom range: last Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an unknown preset, a malformed or future date, or a range over 400 days (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view (`forbidden`)')]
    public function index(Request $request, VisitorAnalytics $analytics): VisitorAnalyticsResource
    {
        $this->authorize('view', SalesAnalytics::class);

        $query = ReportQuery::from($request, ['from', 'to'], ['range']);
        $from = $query->date('from');
        $to = $query->date('to');
        $preset = $query->choice('range', DateRange::presets(), $from !== null || $to !== null ? DateRange::CUSTOM : DateRange::DEFAULT_PRESET);

        try {
            $range = DateRange::fromPreset($preset, $from, $to);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
        if ($range->days() > self::MAX_DAYS) {
            throw new BadRequestHttpException('The range is longer than '.self::MAX_DAYS.' days.');
        }

        return new VisitorAnalyticsResource($analytics->report($range));
    }
}
