<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Health\HealthChecker;
use App\Http\Resources\HealthReportResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/health — public, for the load balancer and uptime checks alike.
 * 200 when every dependency is up, 503 when any is down.
 */
final class HealthController extends ApiController
{
    public function __invoke(Request $request, HealthChecker $checker): JsonResponse
    {
        $report = $checker->run();

        return (new HealthReportResource($report))
            ->response($request)
            ->setStatusCode($report->critical() ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }
}
