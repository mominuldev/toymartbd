<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Health\HealthChecker;
use App\Http\Resources\HealthReportResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/health — public, for the load balancer and uptime checks alike
 * (SYSTEM-ARCHITECTURE §11, docs/ops/observability.md).
 *
 * 200 `ok` · 200 `degraded` (a non-critical dependency failed) · 503 `down` (database, cache
 * or Redis failed). The report is reused for a few seconds (monitoring.health.cache_seconds),
 * so polling cannot load the database. Per-check detail needs `X-Health-Token`.
 */
final class HealthController extends ApiController
{
    /**
     * @response HealthReportResource
     */
    #[HeaderParameter('X-Health-Token', 'Monitoring token (MONITORING_HEALTH_TOKEN); adds per-check detail.', type: 'string')]
    public function __invoke(Request $request, HealthChecker $checker): JsonResponse
    {
        $report = $checker->cached();

        return (new HealthReportResource($report, self::detailAllowed($request)))
            ->response($request)
            ->setStatusCode($report->critical() ? 503 : 200)
            ->header('Cache-Control', 'no-store');
    }

    private static function detailAllowed(Request $request): bool
    {
        $expected = config('monitoring.health.detail_token');
        $given = $request->header('X-Health-Token');

        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }
}
