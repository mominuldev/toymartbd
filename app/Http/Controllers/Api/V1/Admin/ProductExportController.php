<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Import\Enums\SpreadsheetFormat;
use App\Domain\Catalog\Import\Services\ProductExportRows;
use App\Domain\Catalog\Import\Services\ProductSpreadsheet;
use App\Domain\Catalog\Models\Product;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Product\ProductListFilters;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * `GET /products/export` (P4-CAT-10) — the product list as a spreadsheet, one row per
 * variant, with exactly the list's filters (the same allowlist; anything else is a
 * 400), in product id order. Needs catalog.view; `cost_price` is included only with
 * catalog.view_cost. The file is the import format: edit it and upload it back.
 */
final class ProductExportController extends ApiController
{
    #[QueryParameter('format', 'File format.', type: "'csv'|'xlsx'", default: 'csv')]
    #[QueryParameter('filter[search]', 'As on GET /products.', type: 'string')]
    #[QueryParameter('filter[status]', 'As on GET /products.', type: "'draft'|'active'|'archived'")]
    #[QueryParameter('filter[type]', 'As on GET /products.', type: "'simple'|'variable'")]
    #[QueryParameter('filter[brand_id]', 'As on GET /products.', type: 'int')]
    #[QueryParameter('filter[category_id]', 'As on GET /products.', type: 'int')]
    #[OpenApiResponse(400, 'Unknown format, or an unlisted filter or sort (`bad-request`)')]
    public function __invoke(Request $request, ProductExportRows $rows, ProductSpreadsheet $spreadsheet): BinaryFileResponse
    {
        $this->authorize('viewAny', Product::class);

        $format = SpreadsheetFormat::tryFrom((string) $request->query('format', 'csv'));

        if ($format === null) {
            throw new BadRequestHttpException('format must be one of: csv, xlsx.');
        }

        // `format` is this endpoint's own parameter, not a list filter.
        $listRequest = $request->duplicate(query: $request->except('format'));
        $withCost = $request->user()?->can('viewCost', Product::class) ?? false;
        $query = ProductListFilters::query($listRequest)->getEloquentBuilder();

        $path = tempnam(sys_get_temp_dir(), 'products-').'.'.$format->value;
        $spreadsheet->write($path, $format, ProductSpreadsheet::columns($withCost), $rows->rows($query, $withCost));

        return response()->download($path, 'products-'.DhakaDate::today().'.'.$format->value, [
            'Content-Type' => $format->mimeType(),
        ])->deleteFileAfterSend();
    }
}
