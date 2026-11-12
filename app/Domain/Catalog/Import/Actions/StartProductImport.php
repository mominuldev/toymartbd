<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Actions;

use App\Domain\Catalog\Import\Enums\ProductImportStatus;
use App\Domain\Catalog\Import\Enums\SpreadsheetFormat;
use App\Domain\Catalog\Import\Jobs\ProcessProductImport;
use App\Domain\Catalog\Import\Models\ProductImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Store an uploaded product file privately and queue its preview (P4-CAT-10). Nothing
 * is written to the catalog until the preview is confirmed ({@see CommitProductImport}).
 */
final readonly class StartProductImport
{
    public function handle(UploadedFile $file, SpreadsheetFormat $format, int $userId): ProductImport
    {
        $path = $file->storeAs('imports/products', Str::ulid()->toBase32().'.'.$format->value, ['disk' => ProductImport::DISK]);

        $import = ProductImport::query()->create([
            'user_id' => $userId,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => (string) $path,
            'format' => $format,
            'status' => ProductImportStatus::Queued,
        ]);

        ProcessProductImport::dispatch($import->id, false);

        return $import;
    }
}
