<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Import\Models\ProductImport;
use App\Domain\Catalog\Import\Models\ProductImportError;
use App\Http\Resources\ApiResource;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product import and its latest run (P4-CAT-10). Poll it while `status` is
 * `queued`, `previewing` or `importing`; `processed_rows` / `total_rows` is the
 * progress. `errors` holds the first {@see self::ERROR_SAMPLE} problems; the full
 * report is `GET /product-imports/{id}/errors` (CSV).
 *
 * @property ProductImport $resource
 */
#[SchemaName('ProductImport')]
final class ProductImportResource extends ApiResource
{
    public const int ERROR_SAMPLE = 50;

    /**
     * @return array{id: int, original_name: string, format: string, status: string, total_rows: int, processed_rows: int, create_count: int, update_count: int, error_count: int, failure_message: string|null, errors: list<array{row: int, sku: string|null, column: string|null, message: string}>, previewed_at: string|null, committed_at: string|null, completed_at: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        $import = $this->resource;

        $errors = $import->errors()->orderBy('row_number')->orderBy('id')->limit(self::ERROR_SAMPLE)->get()
            ->map(static fn (ProductImportError $e): array => [
                /** Spreadsheet row number (the header is row 1); 0 for a problem with the file itself. */
                'row' => $e->row_number,
                'sku' => $e->sku,
                /** The column the problem belongs to; null for the row (or file) as a whole. */
                'column' => $e->column,
                'message' => $e->message,
            ])->values()->all();

        return [
            'id' => $import->id,
            'original_name' => $import->original_name,
            /** @var 'csv'|'xlsx' */
            'format' => $import->format->value,
            /** @var 'queued'|'previewing'|'previewed'|'importing'|'completed'|'failed' */
            'status' => $import->status->value,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            /** Rows that create (preview: would create) a product. */
            'create_count' => $import->create_count,
            /** Rows that update (preview: would update) a product. */
            'update_count' => $import->update_count,
            /** Rows with at least one problem; they are not written. */
            'error_count' => $import->error_count,
            'failure_message' => $import->failure_message,
            'errors' => $errors,
            /** @format date-time */
            'previewed_at' => self::utcIso($import->previewed_at),
            /** @format date-time */
            'committed_at' => self::utcIso($import->committed_at),
            /** @format date-time */
            'completed_at' => self::utcIso($import->completed_at),
            /**
             * @var string
             *
             * @format date-time
             */
            'created_at' => (string) self::utcIso($import->created_at),
        ];
    }

    private static function utcIso(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }
}
