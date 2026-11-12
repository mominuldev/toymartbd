<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Actions\CompleteImageRenditions;
use App\Domain\Catalog\Media\Actions\CompleteMediaRenditions;
use App\Domain\Catalog\Media\Services\RenditionWriter;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
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

    public function handle(RenditionWriter $writer, CompleteImageRenditions $complete, CompleteMediaRenditions $completeMedia): void
    {
        $image = ProductImage::query()->find($this->imageId);

        if ($image === null || $image->path === null) {
            return; // deleted before the job ran
        }

        // A library-linked image: the renditions belong to its media row (shared by every
        // product that uses it), so completing them updates and revalidates all of those.
        $media = $image->linkedMedia();

        if ($media !== null) {
            $completeMedia->handle($media->id, $media->base_path, $writer->write($media->base_path));

            return;
        }

        $result = $writer->write($image->path);
        $complete->handle($image, $result['widths']);
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Product image renditions failed.', ['image_id' => $this->imageId, 'error' => $e?->getMessage()]);

        $mediaId = ProductImage::query()->whereKey($this->imageId)->value('media_id');

        if (is_numeric($mediaId)) {
            app(CompleteMediaRenditions::class)->fail((int) $mediaId);

            return;
        }

        app(CompleteImageRenditions::class)->fail($this->imageId);
    }
}
