<?php

declare(strict_types=1);

namespace App\Domain\Content\Jobs;

use App\Domain\Content\Blocks\Enums\VideoLengthStatus;
use App\Domain\Content\Blocks\Models\VideoLength;
use App\Domain\Content\Blocks\Services\VideoLengthProbe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Looks up one reel video's length (VideoLengthProbe) and stores it in `video_lengths`. Queued
 * after the block's transaction commits. A network error or provider 5xx is retried with backoff;
 * after the last try the row is marked failed and the daily sweep (toymart:content:video-lengths)
 * tries again. A newly found or changed length revalidates the storefront's content pages.
 */
final class FetchVideoLength implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly string $url)
    {
        $this->onQueue('index');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 600];
    }

    public function handle(VideoLengthProbe $probe): void
    {
        $row = VideoLength::query()->where('url_hash', VideoLength::hashOf($this->url))->first();
        if ($row === null) {
            return;
        }

        $row->increment('attempts');
        [$status, $seconds] = $probe->probe($this->url);
        $changed = $status === VideoLengthStatus::Found && $row->seconds !== $seconds;
        $row->forceFill(['status' => $status, 'seconds' => $seconds ?? ($status === VideoLengthStatus::Found ? $row->seconds : null), 'checked_at' => now()])->save();

        if ($changed) {
            RevalidateStorefrontTags::dispatch(['content:home', 'content:pages']);
        }
    }

    public function failed(?Throwable $e): void
    {
        VideoLength::query()->where('url_hash', VideoLength::hashOf($this->url))
            ->update(['status' => VideoLengthStatus::Failed->value, 'checked_at' => now()]);
    }
}
