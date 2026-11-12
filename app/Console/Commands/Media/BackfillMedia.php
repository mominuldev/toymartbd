<?php

declare(strict_types=1);

namespace App\Console\Commands\Media;

use App\Domain\Catalog\Media\Services\ProductImageMediaBackfill;
use Illuminate\Console\Command;

/**
 * Link existing product images to media-library rows (docs/api/media-library.md).
 * Idempotent: safe to re-run; the migration 2026_11_17_100300 runs the same code
 * without measuring, and this command then fills in the byte counts.
 */
final class BackfillMedia extends Command
{
    protected $signature = 'toymart:media:backfill {--no-measure : Skip reading file sizes from the disks}';

    protected $description = 'Create a media row for every product image without one, and record file sizes';

    public function handle(ProductImageMediaBackfill $backfill): int
    {
        $result = $backfill->run(measure: ! (bool) $this->option('no-measure'));

        $this->info("Linked {$result['linked']} product image(s); created {$result['created']} media row(s); measured {$result['measured']}.");

        return self::SUCCESS;
    }
}
