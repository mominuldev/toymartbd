<?php

declare(strict_types=1);

namespace App\Console\Commands\Content;

use App\Domain\Content\Blocks\Services\ScheduleBoundarySweep;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Events\BannersChanged;
use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Models\Banner;
use App\Domain\Content\Models\CmsPage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The ONE scheduled-content sweep (every minute, routes/console.php). Whatever goes live or
 * expires on a clock rather than on a save gets its storefront cache tags purged — otherwise a
 * cached page would keep showing or hiding it until the ISR ceiling (an hour):
 *
 * - homepage CMS blocks: a `starts_at`/`ends_at` of the live version passed → `content:home`
 *   (ScheduleBoundarySweep, which keeps its own per-placement window);
 * - banners: a start or end passed → `content:banners`;
 * - static pages: a publish time passed → the page's tags (CmsPageChanged → content:page:{slug}
 *   + page:{slug}, content:pages, content:menus).
 *
 * The banner/page window is (last run, now], so a missed minute is caught up. This replaced
 * two commands that both ran every minute (`toymart:content:revalidate-schedule` for blocks
 * was folded in here; nothing referenced it, so no alias is kept).
 */
final class RevalidateScheduledStoreContentCommand extends Command
{
    public const string CACHE_KEY = 'content:store-content-schedule:last-run';

    /** First run, or a cache wipe: look back this far. */
    private const int DEFAULT_LOOKBACK_MINUTES = 10;

    protected $signature = 'toymart:content:revalidate-scheduled';

    protected $description = 'Purge storefront caches for homepage blocks, banners and static pages whose schedule boundary just passed';

    public function handle(ScheduleBoundarySweep $blocks): int
    {
        $now = CarbonImmutable::now('UTC');

        $blockTags = $blocks->run($now);

        $last = Cache::get(self::CACHE_KEY);
        $since = is_string($last) ? CarbonImmutable::parse($last) : $now->subMinutes(self::DEFAULT_LOOKBACK_MINUTES);

        $banners = Banner::query()
            ->where('is_active', true)
            ->where(static function ($q) use ($since, $now): void {
                $q->where(static fn ($w) => $w->where('starts_at', '>', $since)->where('starts_at', '<=', $now))
                    ->orWhere(static fn ($w) => $w->where('ends_at', '>', $since)->where('ends_at', '<=', $now));
            })
            ->exists();

        if ($banners) {
            BannersChanged::dispatch();
        }

        $pages = CmsPage::query()
            ->where('status', PageStatus::Published->value)
            ->where('published_at', '>', $since)
            ->where('published_at', '<=', $now)
            ->get(['id', 'slug']);

        foreach ($pages as $page) {
            CmsPageChanged::dispatch($page->id, $page->slug);
        }

        Cache::forever(self::CACHE_KEY, $now->toIso8601String());

        $this->line(sprintf(
            'Blocks revalidated: %s · banners changed: %s · pages published: %d',
            $blockTags === [] ? 'none' : implode(', ', $blockTags),
            $banners ? 'yes' : 'no',
            $pages->count(),
        ));

        return self::SUCCESS;
    }
}
