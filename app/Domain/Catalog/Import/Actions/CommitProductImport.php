<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Actions;

use App\Domain\Catalog\Exceptions\CatalogConflict;
use App\Domain\Catalog\Import\Enums\ProductImportStatus;
use App\Domain\Catalog\Import\Jobs\ProcessProductImport;
use App\Domain\Catalog\Import\Models\ProductImport;
use Illuminate\Support\Facades\DB;

/**
 * Confirm a previewed import and queue the real run (P4-CAT-10). Only a `previewed`
 * file with at least one valid row can be committed, and only once: the status flips
 * under a row lock, so a double click queues one run.
 */
final readonly class CommitProductImport
{
    /** @throws CatalogConflict */
    public function handle(ProductImport $import): ProductImport
    {
        return DB::transaction(function () use ($import): ProductImport {
            $import = ProductImport::query()->lockForUpdate()->findOrFail($import->id);

            if ($import->status !== ProductImportStatus::Previewed) {
                throw new CatalogConflict('Only a previewed import can be committed; this one is '.$import->status->value.'.');
            }

            if ($import->create_count + $import->update_count === 0) {
                throw new CatalogConflict('Every row in this file has a problem: fix the file and upload it again.');
            }

            $import->forceFill(['status' => ProductImportStatus::Queued, 'committed_at' => now()])->save();

            ProcessProductImport::dispatch($import->id, true);

            return $import;
        });
    }
}
