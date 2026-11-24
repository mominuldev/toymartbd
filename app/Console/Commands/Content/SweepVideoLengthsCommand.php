<?php

declare(strict_types=1);

namespace App\Console\Commands\Content;

use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\VideoLengths;
use Illuminate\Console\Command;

/**
 * Daily: queues a length lookup for every video reel link in the drafts and the newest published
 * versions that has none yet, and retries the pending, failed and "no YouTube key" ones (so adding
 * YOUTUBE_API_KEY fills the YouTube lengths in on the next run).
 */
final class SweepVideoLengthsCommand extends Command
{
    protected $signature = 'toymart:content:video-lengths';

    protected $description = 'Look up the length of every home video reel that has none yet, and retry failed lookups';

    public function handle(): int
    {
        $urls = [];
        foreach (CmsBlock::query()->where('type', BlockType::VideoReel->value)->get() as $block) {
            $urls = [...$urls, ...VideoLengths::urls($block->content)];
        }
        $latest = CmsLayoutVersion::query()->selectRaw('placement, MAX(version) as version')->groupBy('placement')->get();
        foreach ($latest as $row) {
            $version = CmsLayoutVersion::query()->where('placement', $row->getRawOriginal('placement'))->where('version', $row->version)->first();
            foreach ($version === null ? [] : $version->blocks as $block) {
                if (($block['type'] ?? null) === BlockType::VideoReel->value && is_array($block['content'] ?? null)) {
                    $urls = [...$urls, ...VideoLengths::urls($block['content'])];
                }
            }
        }

        $urls = array_values(array_unique($urls));
        foreach ($urls as $url) {
            VideoLengths::queue($url, retry: true);
        }
        $this->info(count($urls).' reel video link(s) checked.');

        return self::SUCCESS;
    }
}
