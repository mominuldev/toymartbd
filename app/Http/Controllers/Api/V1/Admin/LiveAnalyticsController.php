<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\Queries\Live\LiveAnalytics;
use App\Domain\Reporting\Queries\Sales\SalesAnalytics;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\Reports\LiveAnalyticsResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Analytics › Live (Figma B · 24 premium pass 522:81629 / 522:82129): what is happening in the
 * shop right now. Read-only; reports.view (the analytics policy). Takes no parameters — every
 * window is the server's clock — so any query parameter is a 400 (rule 16).
 */
final class LiveAnalyticsController extends ApiController
{
    /** `GET /analytics/live` */
    #[OpenApiResponse(400, 'Any query parameter: this endpoint takes none (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view (`forbidden`)')]
    public function index(Request $request, LiveAnalytics $live, TrackingSettings $settings): LiveAnalyticsResource
    {
        $this->authorize('view', SalesAnalytics::class);

        $unknown = array_map(strval(...), array_keys($request->query->all()));
        if ($unknown !== []) {
            throw new BadRequestHttpException('Unknown parameter(s): '.implode(', ', $unknown).'. This endpoint takes none.');
        }

        $config = $settings->publicConfig();

        return new LiveAnalyticsResource($live->report([
            'consent_mode' => $config['consent_mode'],
            'enabled' => $config['enabled'],
        ]));
    }
}
