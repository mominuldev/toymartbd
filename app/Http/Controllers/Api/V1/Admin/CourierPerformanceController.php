<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Reports\CourierPerformanceReport;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\CourierPerformanceResource;
use App\Support\CsvReport;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Courier performance by provider and by district (P6). Read-only; reports.view +
 * shipments.view, the CSV also reports.export.
 */
final class CourierPerformanceController extends ApiController
{
    /** `GET /reports/couriers` */
    #[QueryParameter('filter[from]', 'First Dhaka date (inclusive), YYYY-MM-DD. Default: six days before today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'Last Dhaka date (inclusive), YYYY-MM-DD. Default: today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[provider]', 'Only this courier (`courier_providers.code`).', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, an unknown provider or a malformed date (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing reports.view or shipments.view (`forbidden`)')]
    public function index(Request $request, CourierPerformanceReport $report): CourierPerformanceResource
    {
        $this->authorize('view', CourierPerformanceReport::class);

        return new CourierPerformanceResource($this->report($request, $report));
    }

    /** `GET /reports/couriers/export` — the provider rows, then the district rows, as CSV. */
    #[QueryParameter('filter[from]', 'As on GET /reports/couriers.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /reports/couriers.', type: 'string', format: 'date')]
    #[QueryParameter('filter[provider]', 'As on GET /reports/couriers.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted parameter or filter (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing the view permissions or reports.export (`forbidden`)')]
    public function export(Request $request, CourierPerformanceReport $report): BinaryFileResponse
    {
        $this->authorize('export', CourierPerformanceReport::class);
        $data = $this->report($request, $report);
        $metrics = ['booked', 'delivered', 'partially_delivered', 'returned', 'cancelled', 'in_flight', 'delivery_rate', 'return_rate', 'average_days_to_deliver', 'average_charge', 'cod_expected', 'cod_collected', 'cod_outstanding'];
        $line = static fn (string $scope, string $code, string $name, string $nameBn, array $row): array => [
            $scope, $code, $name, $nameBn,
            ...array_map(static fn (string $m): string => (string) ($row[$m] ?? ''), $metrics),
        ];

        $rows = [$line('total', '', (string) __('reports.couriers.all'), '', $data['totals'])];
        foreach ($data['providers'] as $row) {
            $rows[] = $line('provider', $row['provider']['code'], $row['provider']['name'], (string) $row['provider']['name_bn'], $row);
        }
        foreach ($data['districts'] as $row) {
            $rows[] = $line('district', $row['district'] === null ? '' : (string) $row['district']['id'], $row['district']['name'] ?? (string) __('reports.couriers.no_district'), (string) ($row['district']['name_bn'] ?? ''), $row);
        }

        return CsvReport::download("courier-performance-{$data['from']}-to-{$data['to']}.csv", ['scope', 'code', 'name', 'name_bn', ...$metrics], $rows);
    }

    /** @return array<string, mixed> */
    private function report(Request $request, CourierPerformanceReport $report): array
    {
        $query = ReportQuery::from($request, ['from', 'to', 'provider']);
        $period = $query->period();
        $provider = $query->filterIn('provider');
        if ($provider !== null && ! CourierProvider::query()->where('code', $provider)->exists()) {
            throw new BadRequestHttpException('filter[provider] is not a courier provider.');
        }

        return $report->report($period->fromDate(), $period->toDate(), $provider);
    }
}
