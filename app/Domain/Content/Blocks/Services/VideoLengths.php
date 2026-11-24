<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Content\Blocks\Enums\VideoLengthStatus;
use App\Domain\Content\Blocks\Models\VideoLength;
use App\Domain\Content\Blocks\ValueObjects\VideoEmbed;
use App\Domain\Content\Jobs\FetchVideoLength;

/**
 * Detected lengths of the videos reels link to (`video_lengths`). The block Actions call
 * queueFor() inside their transaction; the lookup itself runs in FetchVideoLength after commit
 * (rule 4: no external call inside a transaction). The resolver reads secondsFor(): the
 * storefront shows the editor's typed `duration`, else the detected one.
 */
final class VideoLengths
{
    /** @param  array<string, mixed>  $content  a block's stored content */
    public static function queueFor(array $content): void
    {
        foreach (self::urls($content) as $url) {
            self::queue($url);
        }
    }

    public static function queue(string $url, bool $retry = false): void
    {
        $embed = VideoEmbed::fromUrl($url);
        if ($embed === null) {
            return;
        }

        $row = VideoLength::query()->firstOrCreate(['url_hash' => VideoLength::hashOf($url)], [
            'url' => $url,
            'provider' => $embed->kind,
            'status' => in_array($embed->kind, [VideoEmbed::FACEBOOK, VideoEmbed::INSTAGRAM], true) ? VideoLengthStatus::Unsupported : VideoLengthStatus::Pending,
        ]);

        if ($row->wasRecentlyCreated ? $row->status === VideoLengthStatus::Pending : ($retry && $row->status->retryable())) {
            FetchVideoLength::dispatch($url)->afterCommit();
        }
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, int> url → seconds, for the lengths found
     */
    public static function secondsFor(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $byHash = [];
        foreach ($urls as $url) {
            $byHash[VideoLength::hashOf($url)] = $url;
        }

        $out = [];
        foreach (VideoLength::query()->whereIn('url_hash', array_keys($byHash))->where('status', VideoLengthStatus::Found->value)->get(['url_hash', 'seconds']) as $row) {
            if ($row->seconds !== null && $row->seconds > 0) {
                $out[$byHash[$row->url_hash]] = $row->seconds;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $urls
     * @return array<string, array{seconds: int|null, status: string}> url → what is known, for the admin
     */
    public static function statusFor(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $byHash = [];
        foreach ($urls as $url) {
            $byHash[VideoLength::hashOf($url)] = $url;
        }

        $out = [];
        foreach (VideoLength::query()->whereIn('url_hash', array_keys($byHash))->get(['url_hash', 'seconds', 'status']) as $row) {
            $out[$byHash[$row->url_hash]] = ['seconds' => $row->seconds, 'status' => $row->status->value];
        }

        return $out;
    }

    /** 42 → "0:42", 754 → "12:34", 3725 → "1:02:05" (Western digits, rule 36). */
    public static function format(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /**
     * The reel video links in one block's content.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function urls(array $content): array
    {
        $urls = [];
        foreach (is_array($content['items'] ?? null) ? $content['items'] : [] as $item) {
            if (is_array($item) && is_string($item['video_url'] ?? null) && $item['video_url'] !== '') {
                $urls[] = $item['video_url'];
            }
        }

        return array_values(array_unique($urls));
    }
}
