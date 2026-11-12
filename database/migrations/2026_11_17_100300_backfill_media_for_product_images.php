<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Services\ProductImageMediaBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * Link every existing product image to a media row (one per distinct file base, so a
 * duplicated product shares its source's media). Chunked by id with one short
 * transaction per chunk — no table-wide lock. Idempotent: the same code runs as
 * `php artisan toymart:media:backfill`, which is safe to re-run at any time.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(ProductImageMediaBackfill::class)->run(measure: false);
    }

    public function down(): void
    {
        // Nothing to undo: dropping product_images.media_id (previous migration) unlinks.
    }
};
