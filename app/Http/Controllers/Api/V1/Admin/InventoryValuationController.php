<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Reports\FinanceReport;
use App\Domain\Inventory\Reports\InventoryValuationReport;
use App\Domain\Reporting\Exports\ReportSheets;
use App\Domain\Reporting\Exports\Spreadsheet;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\InventoryValuationResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory valuation at cost (P5): now from the stock projection, or as at a past Dhaka
 * date replayed from the movement ledger. Read-only. Needs catalog.view_cost and either
 * finance.view or inventory.view; the CSV / XLSX export also needs finance.export.
 */
final class InventoryValuationController extends ApiController
{
    /** `GET /reports/inventory-valuation` */
    #[QueryParameter('filter[as_at]', 'Value as at the end of this Dhaka day, YYYY-MM-DD (today or earlier). Default: now, from the live projection.', type: 'string', format: 'date')]
    #[QueryParameter('filter[location_id]', 'Only this stock location.', type: 'int')]
    #[QueryParameter('filter[category_id]', 'Only products whose primary category is this one or below it.', type: 'int')]
    #[QueryParameter('filter[search]', 'Contains-match on product name, name_bn or SKU.', type: 'string')]
    #[QueryParameter('sort', 'value, on_hand, unit_cost or name; prefix - for descending.', type: 'string', default: '-value')]
    #[OpenApiResponse(400, 'Unlisted parameter, filter or sort, or an invalid value (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing catalog.view_cost, or both finance.view and inventory.view (`forbidden`)')]
    public function index(Request $request, InventoryValuationReport $valuation): InventoryValuationResource
    {
        $this->authorize('viewValuation', FinanceReport::class);

        return new InventoryValuationResource($this->report(self::query($request, []), $valuation));
    }

    /** `GET /reports/inventory-valuation/export` — every row, then the total, as CSV or XLSX. */
    #[QueryParameter('filter[as_at]', 'As on GET /reports/inventory-valuation.', type: 'string', format: 'date')]
    #[QueryParameter('filter[location_id]', 'As on GET /reports/inventory-valuation.', type: 'int')]
    #[QueryParameter('filter[category_id]', 'As on GET /reports/inventory-valuation.', type: 'int')]
    #[QueryParameter('filter[search]', 'As on GET /reports/inventory-valuation.', type: 'string')]
    #[QueryParameter('sort', 'As on GET /reports/inventory-valuation.', type: 'string', default: '-value')]
    #[QueryParameter('format', 'csv (default, unchanged since P5) or xlsx (one worksheet, numbers as numeric cells).', type: "'csv'|'xlsx'", default: 'csv')]
    #[QueryParameter('locale', 'Language of the labels and the sheet name. Default: the request locale.', type: "'en'|'bn'")]
    #[OpenApiResponse(400, 'Unlisted parameter, filter or sort, or an unknown format or locale (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing the view permissions or finance.export (`forbidden`)')]
    public function export(Request $request, InventoryValuationReport $valuation): BinaryFileResponse|StreamedResponse
    {
        $this->authorize('exportValuation', FinanceReport::class);
        $query = self::query($request, ['format', 'locale']);
        [$format, $locale] = $query->exportOptions();
        $report = $this->report($query, $valuation);

        return Spreadsheet::downloadSheet(
            'inventory-valuation-'.($report['as_at'] ?? DhakaDate::today()),
            $format,
            ReportSheets::inventoryValuation($report, $locale)[0],
        );
    }

    /** @param  list<string>  $extra  further allowed parameters (the export's format and locale) */
    private static function query(Request $request, array $extra): ReportQuery
    {
        return ReportQuery::from($request, ['as_at', 'location_id', 'category_id', 'search'], ['sort', ...$extra]);
    }

    /** @return array<string, mixed> */
    private function report(ReportQuery $query, InventoryValuationReport $valuation): array
    {
        $sort = $query->sort(InventoryValuationReport::SORTS, '-value');
        $asAt = $query->date('as_at');

        return [
            ...$valuation->report([
                'location_id' => $query->locationId(),
                'category_id' => $query->id('category_id'),
                'search' => $query->search(),
            ], $asAt, $sort),
            'sort' => $sort,
        ];
    }
}
