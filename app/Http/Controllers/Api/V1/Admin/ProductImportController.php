<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Import\Actions\CommitProductImport;
use App\Domain\Catalog\Import\Actions\StartProductImport;
use App\Domain\Catalog\Import\Enums\SpreadsheetFormat;
use App\Domain\Catalog\Import\Models\ProductImport;
use App\Domain\Catalog\Import\Models\ProductImportError;
use App\Domain\Catalog\Import\Services\ProductSpreadsheet;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ProductImport\StoreProductImportRequest;
use App\Http\Resources\Admin\ProductImportResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk product import (P4-CAT-10). Upload → a queued preview checks every row through
 * the product FormRequest and actions without keeping anything → staff read the
 * preview (counts + row-level problems) → commit queues the real run. All need
 * catalog.import; each row is then authorised as the product endpoints authorise it.
 */
final class ProductImportController extends ApiController
{
    /** `POST /product-imports` — store the file and queue its preview (202). */
    #[OpenApiResponse(403, 'Missing catalog.import (`forbidden`)')]
    public function store(StoreProductImportRequest $request, StartProductImport $start): JsonResponse
    {
        $import = $start->handle($request->upload(), $request->spreadsheetFormat(), (int) $request->user()?->id);

        return (new ProductImportResource($import->refresh()))->response()->setStatusCode(202);
    }

    /** `GET /product-imports/{import}` — status, progress, counts and the first problems. Poll while running. */
    public function show(ProductImport $import): ProductImportResource
    {
        $this->authorize('view', $import);

        return new ProductImportResource($import);
    }

    /** `POST /product-imports/{import}/commit` — import the previewed file's valid rows (202). */
    #[OpenApiResponse(409, 'Not previewed yet, already committed, or no valid rows (`conflict`)')]
    public function commit(ProductImport $import, CommitProductImport $commit): JsonResponse
    {
        $this->authorize('commit', $import);

        return (new ProductImportResource($commit->handle($import)))->response()->setStatusCode(202);
    }

    /**
     * `GET /product-imports/{import}/errors` — the full row-level report as CSV
     * (UTF-8 with BOM): row, sku, column, message.
     */
    public function errors(ProductImport $import, ProductSpreadsheet $spreadsheet): BinaryFileResponse
    {
        $this->authorize('view', $import);

        $path = tempnam(sys_get_temp_dir(), 'import-errors-').'.csv';
        $rows = $import->errors()->orderBy('row_number')->orderBy('id')->lazy()->map(static fn (ProductImportError $e): array => [
            'row' => (string) $e->row_number,
            'sku' => $e->sku,
            'column' => $e->column,
            'message' => $e->message,
        ]);

        $spreadsheet->write($path, SpreadsheetFormat::Csv, ['row', 'sku', 'column', 'message'], $rows);

        return response()->download($path, 'product-import-'.$import->id.'-errors.csv', [
            'Content-Type' => SpreadsheetFormat::Csv->mimeType(),
        ])->deleteFileAfterSend();
    }
}
