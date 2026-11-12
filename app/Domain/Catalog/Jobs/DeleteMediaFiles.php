<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Remove a media base directory (master + renditions) after the row that pointed at
 * it is gone. Queued after commit (rule 4). Skips a base another product image or a
 * media row still references — a duplicated product shares its source's files.
 */
final class DeleteMediaFiles implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $base)
    {
        $this->afterCommit();
        $queue = config('media.queue');
        $this->onQueue(is_string($queue) ? $queue : 'index');
    }

    public function handle(): void
    {
        if ($this->base === '' || str_contains($this->base, '..')) {
            return;
        }

        // Still referenced: a product image row (an unlinked duplicate shares its source's
        // base) or a media row (the library's source of truth).
        if (ProductImage::query()->whereNull('media_id')->where('path', $this->base)->exists()
            || Media::query()->where('base_path', $this->base)->exists()) {
            return;
        }

        Storage::disk(MediaUrls::disk())->deleteDirectory($this->base);
        Storage::disk(MediaUrls::originalsDisk())->deleteDirectory($this->base);
    }
}
