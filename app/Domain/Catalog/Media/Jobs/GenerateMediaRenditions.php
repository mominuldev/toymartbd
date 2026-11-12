<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Jobs;

use App\Domain\Catalog\Media\Actions\CompleteMediaRenditions;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\RenditionWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write the WebP/AVIF renditions of a media image from its private master, then mark it
 * `ready`. Queued after commit on `media.queue`, like the product-image job it shares
 * the RenditionWriter with. Idempotent: re-running overwrites the same immutable paths.
 */
final class GenerateMediaRenditions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $mediaId)
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

    public function handle(RenditionWriter $writer, CompleteMediaRenditions $complete): void
    {
        $media = Media::query()->find($this->mediaId);

        if ($media === null || ! $media->isImage()) {
            return; // deleted before the job ran, or a document
        }

        $base = $media->base_path;
        $complete->handle($media->id, $base, $writer->write($base));
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Media renditions failed.', ['media_id' => $this->mediaId, 'error' => $e?->getMessage()]);

        app(CompleteMediaRenditions::class)->fail($this->mediaId);
    }
}
