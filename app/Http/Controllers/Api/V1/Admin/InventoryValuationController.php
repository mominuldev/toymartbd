<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Reports\FinanceReport;
use App\Domain\Inventory\Reports\InventoryValuationReport;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Reports\ReportQuery;
use App\Http\Resources\Admin\Reports\InventoryValuationResource;
use App\Support\CsvReport;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Inventory valuation at cost (P5): now from the stock projection, or as at a past Dhaka
 * date replayed from the movement ledger. Read-only. Needs catalog.view_cost and either
 * finance.view or inventory.view; the CSV also needs finance.export.
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

        return new InventoryValuationResource($this->report($request, $valuation));
    }

    /** `GET /reports/inventory-valuation/export` — every row as CSV. */
    #[QueryParameter('filter[as_at]', 'As on GET /reports/inventory-valuation.', type: 'string', format: 'date')]
    #[QueryParameter('filter[location_id]', 'As on GET /reports/inventory-valuation.', type: 'int')]
    #[QueryParameter('filter[category_id]', 'As on GET /reports/inventory-valuation.', type: 'int')]
    #[QueryParameter('filter[search]', 'As on GET /reports/inventory-valuation.', type: 'string')]
    #[QueryParameter('sort', 'As on GET /reports/inventory-valuation.', type: 'string', default: '-value')]
    #[OpenApiResponse(400, 'Unlisted parameter, filter or sort (`bad-request`)')]
    #[OpenApiResponse(403, 'Missing the view permissions or finance.export (`forbidden`)')]
    public function export(Request $request, InventoryValuationReport $valuation): BinaryFileResponse
    {
        $this->authorize('exportValuation', FinanceReport::class);
        $report = $this->report($request, $valuation);
        $columns = ['sku', 'name', 'name_bn', 'variant_name', 'category_name', 'location_code', 'location_name', 'on_hand', 'unit_cost', 'value', 'estimated'];

        $rows = array_map(static fn (array $row): array => array_map(
            static fn (string $c): string => is_bool($row[$c]) ? ($row[$c] ? '1' : '0') : (string) ($row[$c] ?? ''),
            $columns,
        ), $report['rows']);
        $rows[] = ['', __('reports.valuation.total'), '', '', '', '', '', (string) $report['totals']['on_hand'], '', $report['totals']['value'], ''];

        return CsvReport::download('inventory-valuation-'.($report['as_at'] ?? DhakaDate::today()).'.csv', $columns, $rows);
    }

    /** @return array<string, mixed> */
    private function report(Request $request, InventoryValuationReport $valuation): array
    {
        $query = ReportQuery::from($request, ['as_at', 'location_id', 'category_id', 'search'], ['sort']);
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
