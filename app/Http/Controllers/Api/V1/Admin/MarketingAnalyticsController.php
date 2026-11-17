<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Marketing\Models\AdSpend;
use App\Domain\Reporting\Queries\Marketing\MarketingAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\MarketingAnalyticsResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Marketing ROI (P10; Figma B · 24 premium pass 522:81016): what each campaign, coupon and
 * promotion brought in against what it cost. Read-only; reports.view (AdSpendPolicy::viewAny).
 */
final class MarketingAnalyticsController extends ApiController
{
    /** `GET /analytics/marketing` */
    #[QueryParameter('range', 'Preset: today, 7d, 30d, this_month, last_month or custom (needs filter[from] and filter[to]). Default 7d, or custom when dates are given.', type: 'string', default: '7d')]
    #[QueryParameter('filter[from]', 'Custom range: first Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Custom range: last Dhaka date (inclusive), YYYY-MM-DD.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an unknown preset, or a malformed or future date (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view (`forbidden`)')]
    public function index(Request $request, MarketingAnalytics $analytics): MarketingAnalyticsResource
    {
        $this->authorize('viewAny', AdSpend::class);

        return new MarketingAnalyticsResource($analytics->report(self::range($request)));
    }

    /** The Dhaka-date range of a Marketing ROI request — the Sales analytics presets (rule 16 allowlist). */
    public static function range(Request $request): DateRange
    {
        $query = ReportQuery::from($request, ['from', 'to'], ['range']);
        $from = $query->date('from');
        $to = $query->date('to');
        $preset = $query->choice('range', DateRange::presets(), $from !== null || $to !== null ? DateRange::CUSTOM : DateRange::DEFAULT_PRESET);

        try {
            return DateRange::fromPreset($preset, $from, $to);
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
    }
}
