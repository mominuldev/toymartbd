<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Jobs;

use App\Domain\Catalog\Import\Actions\RunProductImport;
use App\Domain\Catalog\Import\Models\ProductImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Preview or commit a product import file (P4-CAT-10) off the request, on the
 * `reports` queue (bulk, not latency-sensitive; the standard worker serves it).
 * One attempt: a half-committed file is re-run by uploading it again, which is safe
 * because rows upsert by SKU.
 */
final class ProcessProductImport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $importId, public readonly bool $commit)
    {
        $this->afterCommit();
        $this->onQueue('reports');
    }

    public function handle(RunProductImport $run): void
    {
        $import = ProductImport::query()->find($this->importId);

        if ($import !== null) {
            $run->handle($import, $this->commit);
        }
    }

    public function failed(?Throwable $e): void
    {
        $import = ProductImport::query()->find($this->importId);

        if ($import !== null) {
            app(RunProductImport::class)->abort($import, $e);
        }
    }
}
