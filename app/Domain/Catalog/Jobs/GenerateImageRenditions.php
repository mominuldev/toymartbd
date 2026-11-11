<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Actions\CompleteImageRenditions;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Write the WebP/AVIF renditions of a product image from its private master, then
 * mark it `ready` (P4-CAT-07). Queued after commit on `media.queue` (default
 * `index`, which the standard worker already serves). Idempotent: re-running
 * overwrites the same immutable paths.
 */
final class GenerateImageRenditions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $imageId)
    {
        $this->afterCommit();
        $queue = config('media.queue');
        $this->onQueue(is_string($queue) ? $queue : 'index');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(ImagePipeline $pipeline, CompleteImageRenditions $complete): void
    {
        $image = ProductImage::query()->find($this->imageId);

        if ($image === null || $image->path === null) {
            return; // deleted before the job ran
        }

        $master = Storage::disk(MediaUrls::originalsDisk())->get("{$image->path}/master.webp");

        if ($master === null) {
            throw new RuntimeException("Master missing for product image {$image->id}.");
        }

        $disk = Storage::disk(MediaUrls::disk());

        $renditions = $pipeline->renditions($master);

        foreach ($renditions as $width => $formats) {
            foreach ($formats as $format => $bytes) {
                $disk->put(MediaUrls::renditionPath($image->path, $width, $format), $bytes, ['visibility' => 'public']);
            }
        }

        $complete->handle($image, array_keys($renditions));
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Product image renditions failed.', ['image_id' => $this->imageId, 'error' => $e?->getMessage()]);

        app(CompleteImageRenditions::class)->fail($this->imageId);
    }
}
