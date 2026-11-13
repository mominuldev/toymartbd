<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Reports\CourierPerformanceReport;
use App\Domain\Reporting\Exports\ReportSheets;
use App\Domain\Reporting\Exports\Spreadsheet;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\CourierPerformanceResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Courier performance by provider and by district (P6). Read-only; reports.view +
 * shipments.view, the CSV / XLSX export also reports.export.
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

        return new CourierPerformanceResource($this->report(ReportQuery::from($request, ['from', 'to', 'provider']), $report));
    }

    /** `GET /reports/couriers/export` — the total, the provider rows, then the district rows, as CSV or XLSX. */
    #[QueryParameter('filter[from]', 'As on GET /reports/couriers.', type: 'string', format: 'date')]
    #[QueryParameter('filter[to]', 'As on GET /reports/couriers.', type: 'string', format: 'date')]
    #[QueryParameter('filter[provider]', 'As on GET /reports/couriers.', type: 'string')]
    #[QueryParameter('format', 'csv (default, unchanged since P5) or xlsx (one worksheet, numbers as numeric cells).', type: "'csv'|'xlsx'", default: 'csv')]
    #[QueryParameter('locale', 'Language of the labels and the sheet name. Default: the request locale.', type: "'en'|'bn'")]
    #[OpenApiResponse(400, 'Unlisted parameter or filter, or an unknown format or locale (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing the view permissions or reports.export (`forbidden`)')]
    public function export(Request $request, CourierPerformanceReport $report): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('export', CourierPerformanceReport::class);
        $query = ReportQuery::from($request, ['from', 'to', 'provider'], ['format', 'locale']);
        [$format, $locale] = $query->exportOptions();
        $data = $this->report($query, $report);

        return Spreadsheet::downloadSheet("courier-performance-{$data['from']}-to-{$data['to']}", $format, ReportSheets::couriers($data, $locale)[0]);
    }

    /** @return array<string, mixed> */
    private function report(ReportQuery $query, CourierPerformanceReport $report): array
    {
        $period = $query->period();
        $provider = $query->filterIn('provider');
        if ($provider !== null && ! CourierProvider::query()->where('code', $provider)->exists()) {
            throw new BadRequestHttpException('filter[provider] is not a courier provider.');
        }

        return $report->report($period->fromDate(), $period->toDate(), $provider);
    }
}
