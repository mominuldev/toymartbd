<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reporting\Queries\Customers\CustomerAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\CustomerAnalyticsResource;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Customer analytics (roadmap Phase 8; admin Analytics → Customers). Read-only; needs
 * reports.view + customers.view. Lifetime value is delivered revenue from the ledger, never
 * placed value; months are Dhaka months (rule 11). Definitions: {@see CustomerAnalytics}.
 */
final class CustomerAnalyticsController extends ApiController
{
    /** Without a range: the last six Dhaka months, this one included. */
    public const int DEFAULT_MONTHS = 6;

    /** `GET /reports/customers` */
    #[QueryParameter('range', 'today|7d|30d|this_month|last_month|custom. Default: the last six Dhaka months (custom).', type: 'string')]
    #[QueryParameter('from', 'First Dhaka date (Y-m-d), with range=custom.', type: 'string', format: 'date')]
    #[QueryParameter('to', 'Last Dhaka date (Y-m-d), with range=custom.', type: 'string', format: 'date')]
    #[OpenApiResponse(400, 'Unlisted parameter, an unknown preset or a malformed date (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view or customers.view (`forbidden`)')]
    public function index(Request $request, CustomerAnalytics $analytics): CustomerAnalyticsResource
    {
        $this->authorize('view', CustomerAnalytics::class);

        ReportQuery::from($request, [], ['range', 'from', 'to']);
        $param = static fn (string $name): ?string => is_string($v = $request->query($name)) && $v !== '' ? $v : null;

        try {
            $range = $param('range') === null && $param('from') === null && $param('to') === null
                ? self::lastMonths(self::DEFAULT_MONTHS)
                : DateRange::fromPreset($param('range'), $param('from'), $param('to'));
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }
        if ($range->toDate() > DhakaDate::today()) {
            throw new BadRequestHttpException('`to` must not be in the future.');
        }

        return new CustomerAnalyticsResource($analytics->report($range));
    }

    private static function lastMonths(int $months): DateRange
    {
        $today = CarbonImmutable::createFromFormat('!Y-m-d', DhakaDate::today(), 'UTC');
        assert($today instanceof CarbonImmutable);

        return DateRange::custom($today->startOfMonth()->subMonthsNoOverflow($months - 1)->toDateString(), $today->toDateString());
    }
}
