<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Actions;

use App\Domain\Catalog\Import\Contracts\ProductRowWriter;
use App\Domain\Catalog\Import\Enums\ProductImportStatus;
use App\Domain\Catalog\Import\Enums\RowOutcome;
use App\Domain\Catalog\Import\Exceptions\RowRejected;
use App\Domain\Catalog\Import\Exceptions\UnreadableSpreadsheet;
use App\Domain\Catalog\Import\Models\ProductImport;
use App\Domain\Catalog\Import\Models\ProductImportError;
use App\Domain\Catalog\Import\Services\ProductImportDocument;
use App\Domain\Catalog\Import\Services\ProductSpreadsheet;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Run an import file, row by row (P4-CAT-10), as a preview or for real.
 *
 * Every row goes through {@see ProductRowWriter}: the product FormRequest's rules and
 * abilities, then Create/UpdateProduct — the same path as the product form, so there
 * is no second copy of the product rules. Each row is its own unit: a bad row is
 * recorded (row number + column + message) and the next row runs; it is never
 * skipped silently.
 *
 * - **Preview** (`$commit = false`) writes each row inside a transaction that is always
 *   rolled back, so it finds everything the real run would — including the domain's
 *   rules — and leaves nothing behind (events held for commit are discarded with it).
 * - **Commit** writes each valid row in its own Action transaction. A row that failed in
 *   the preview fails again and is reported again.
 *
 * Within one file a SKU may appear once; a repeat is reported on the later row.
 */
final readonly class RunProductImport
{
    /** Progress is written every this many rows. */
    private const int PROGRESS_EVERY = 25;

    public function __construct(
        private ProductSpreadsheet $spreadsheet,
        private ProductRowWriter $writer,
    ) {}

    public function handle(ProductImport $import, bool $commit): ProductImport
    {
        $actor = $import->user;

        if ($actor === null) {
            return $this->fail($import, 'The member of staff who uploaded this file no longer exists.');
        }

        $import->errors()->delete();
        $import->fill([
            'status' => $commit ? ProductImportStatus::Importing : ProductImportStatus::Previewing,
            'processed_rows' => 0, 'create_count' => 0, 'update_count' => 0, 'error_count' => 0,
            'failure_message' => null,
        ])->save();

        try {
            $sheet = $this->spreadsheet->read(Storage::disk(ProductImport::DISK)->path($import->path), $import->format);
            $total = $this->count($import);
        } catch (UnreadableSpreadsheet $e) {
            $this->record($import, 0, null, null, $e->getMessage());

            return $this->fail($import, $e->getMessage());
        }

        if ($total > ProductSpreadsheet::MAX_ROWS) {
            $message = 'The file has '.$total.' rows; the limit is '.ProductSpreadsheet::MAX_ROWS.' per file. Split it and import each part.';
            $this->record($import, 0, null, null, $message);

            return $this->fail($import, $message);
        }

        $import->total_rows = $total;
        $import->save();

        $mapper = new ProductImportDocument;
        $seen = [];
        $counts = ['created' => 0, 'updated' => 0, 'errors' => 0, 'processed' => 0];

        foreach ($sheet['rows'] as $number => $row) {
            $sku = isset($row['sku']) ? trim($row['sku']) : '';
            $outcome = null;

            try {
                if ($sku === '') {
                    throw RowRejected::column('sku', 'Every row needs a SKU: it is how the row is matched to a product.');
                }

                if (isset($seen[strtolower($sku)])) {
                    throw RowRejected::column('sku', 'This SKU already appears on row '.$seen[strtolower($sku)].' of this file.');
                }
                $seen[strtolower($sku)] = $number;

                $outcome = $this->row($row, $sku, $mapper, $actor, $commit);
            } catch (RowRejected $e) {
                foreach ($e->problems as $column => $messages) {
                    foreach ($messages as $message) {
                        $this->record($import, $number, $sku, $column === '' ? null : $column, $message);
                    }
                }
                $counts['errors']++;
            }

            if ($outcome === RowOutcome::Created) {
                $counts['created']++;
            } elseif ($outcome === RowOutcome::Updated) {
                $counts['updated']++;
            }

            $counts['processed']++;

            if ($counts['processed'] % self::PROGRESS_EVERY === 0) {
                $this->progress($import, $counts);
            }
        }

        $this->progress($import, $counts);

        $import->status = $commit ? ProductImportStatus::Completed : ProductImportStatus::Previewed;
        $import->forceFill($commit ? ['completed_at' => now()] : ['previewed_at' => now()])->save();

        if ($commit) {
            Storage::disk(ProductImport::DISK)->delete($import->path);
        }

        return $import;
    }

    /**
     * @param  array<string, string|null>  $row
     *
     * @throws RowRejected
     */
    private function row(array $row, string $sku, ProductImportDocument $mapper, User $actor, bool $commit): RowOutcome
    {
        $match = ProductVariant::query()->with('product')->where('sku', $sku)->first();

        $document = $mapper->build($row, $match);

        if ($commit) {
            return $this->write($document, $match, $actor);
        }

        DB::beginTransaction();

        try {
            return $this->write($document, $match, $actor);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws RowRejected
     */
    private function write(array $document, ?ProductVariant $match, User $actor): RowOutcome
    {
        try {
            return $this->writer->write($document, $match?->product, $actor);
        } catch (ValidationException $e) {
            $problems = [];
            foreach ($e->errors() as $field => $messages) {
                $column = ProductImportDocument::column((string) $field);
                foreach ($messages as $message) {
                    $problems[$column ?? ''][] = self::readable((string) $field, $column, (string) $message);
                }
            }

            throw new RowRejected($problems);
        } catch (AuthorizationException $e) {
            throw RowRejected::column(null, $e->getMessage() !== '' && $e->getMessage() !== 'This action is unauthorized.'
                ? $e->getMessage()
                : ($match === null ? 'You do not have permission to create products.' : 'You do not have permission to update this product.'));
        }
    }

    /** "The variants.0.price field is required." → "The price field is required." */
    private static function readable(string $field, ?string $column, string $message): string
    {
        if ($column === null) {
            return $message;
        }

        return str_replace([$field, str_replace('_', ' ', $field)], $column, $message);
    }

    private function count(ProductImport $import): int
    {
        $total = 0;
        foreach ($this->spreadsheet->read(Storage::disk(ProductImport::DISK)->path($import->path), $import->format)['rows'] as $_) {
            $total++;
        }

        return $total;
    }

    /** @param  array{created: int, updated: int, errors: int, processed: int}  $counts */
    private function progress(ProductImport $import, array $counts): void
    {
        $import->forceFill([
            'processed_rows' => $counts['processed'],
            'create_count' => $counts['created'],
            'update_count' => $counts['updated'],
            'error_count' => $counts['errors'],
        ])->save();
    }

    private function record(ProductImport $import, int $row, ?string $sku, ?string $column, string $message): void
    {
        ProductImportError::query()->create([
            'import_id' => $import->id,
            'row_number' => $row,
            'sku' => $sku === null || $sku === '' ? null : mb_substr($sku, 0, 64),
            'column' => $column,
            'message' => mb_substr($message, 0, 1000),
        ]);
    }

    private function fail(ProductImport $import, string $message): ProductImport
    {
        $import->forceFill(['status' => ProductImportStatus::Failed, 'failure_message' => mb_substr($message, 0, 500)])->save();

        return $import;
    }

    /** Mark an import failed after an unexpected error (the job's failed() hook). */
    public function abort(ProductImport $import, ?Throwable $e): void
    {
        Log::error('Product import failed.', ['import_id' => $import->id, 'error' => $e?->getMessage()]);

        $this->fail($import, 'The import stopped unexpectedly. Rows already imported stay imported; upload the file again to finish.');
    }
}
