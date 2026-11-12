<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Remove a replaced or detached certificate PDF from the originals disk, after commit
 * (rule 4). Skips a key a product still points at.
 */
final class DeleteComplianceCertificate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $key)
    {
        $this->afterCommit();
        $queue = config('media.queue');
        $this->onQueue(is_string($queue) ? $queue : 'index');
    }

    public function handle(): void
    {
        if (! str_starts_with($this->key, 'compliance/') || str_contains($this->key, '..')) {
            return;
        }

        if (Product::withTrashed()->where('bsti_certificate_path', $this->key)->exists()) {
            return;
        }

        Storage::disk(MediaUrls::originalsDisk())->delete($this->key);
    }
}
