<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Marketing\Actions\CreateAdSpend;
use App\Domain\Marketing\Actions\DeleteAdSpend;
use App\Domain\Marketing\Actions\UpdateAdSpend;
use App\Domain\Marketing\Models\AdSpend;
use App\Domain\Reporting\Queries\Marketing\MarketingAnalytics;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\SaveAdSpendRequest;
use App\Http\Resources\Admin\Reports\AdSpendResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Ad spend typed in by staff for Marketing ROI (P10) — we never read an ad account. Listing
 * needs reports.view; adding, changing and removing need marketing.manage, and are audited.
 */
final class AdSpendController extends ApiController
{
    /** `GET /analytics/marketing/spend` — the entries whose month overlaps the range, newest month first; `meta.campaigns` are campaign tags seen on orders in the last year, for the form. */
    #[QueryParameter('range', 'As on GET /analytics/marketing.', type: 'string', default: '7d')]
    #[QueryParameter('filter[from]', 'As on GET /analytics/marketing.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /analytics/marketing.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, or an invalid value (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view (`forbidden`)')]
    public function index(Request $request, MarketingAnalytics $analytics): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AdSpend::class);
        $range = MarketingAnalyticsController::range($request);

        $rows = AdSpend::query()
            ->with(['creator', 'updater'])
            ->where('month', '>=', substr($range->fromDate(), 0, 7).'-01')
            ->where('month', '<', \Carbon\CarbonImmutable::parse($range->toDate(), 'UTC')->addDay()->toDateString())
            ->orderByDesc('month')
            ->orderBy('campaign')
            ->orderBy('id')
            ->get();

        return AdSpendResource::collection($rows)->additional(['meta' => [
            /** Campaign tags seen on orders in the last 365 days, most recent first. */
            'campaigns' => $analytics->campaignSuggestions(),
        ]]);
    }

    /** `POST /analytics/marketing/spend` */
    #[OpenApiResponse(403, 'Missing marketing.manage (`forbidden`)')]
    public function store(SaveAdSpendRequest $request, CreateAdSpend $create): JsonResponse
    {
        $spend = $create->handle($request->campaign(), $request->source(), $request->monthStart(), $request->amount(), $request->note(), $request->user()?->id);

        return (new AdSpendResource($spend))->response()->setStatusCode(201);
    }

    /** `PUT /analytics/marketing/spend/{adSpend}` */
    #[OpenApiResponse(403, 'Missing marketing.manage (`forbidden`)')]
    public function update(SaveAdSpendRequest $request, AdSpend $adSpend, UpdateAdSpend $update): AdSpendResource
    {
        return new AdSpendResource($update->handle($adSpend, $request->campaign(), $request->source(), $request->monthStart(), $request->amount(), $request->note(), $request->user()?->id));
    }

    /** `DELETE /analytics/marketing/spend/{adSpend}` */
    #[OpenApiResponse(403, 'Missing marketing.manage (`forbidden`)')]
    public function destroy(Request $request, AdSpend $adSpend, DeleteAdSpend $delete): Response
    {
        $this->authorize('delete', $adSpend);
        $delete->handle($adSpend, $request->user()?->id);

        return response()->noContent();
    }
}
