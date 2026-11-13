<?php

declare(strict_types=1);

namespace App\Console\Commands\Content;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Events\BannersChanged;
use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Models\Banner;
use App\Domain\Content\Models\CmsPage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled banners and static pages: a banner whose start or end, or a page whose publish time,
 * passed since the last run gets its storefront cache tags purged (content:banners,
 * content:page:{slug}) — otherwise a cached page would keep showing or hiding it until the ISR
 * ceiling. Runs every minute; the window is (last run, now], so a missed minute is caught up.
 */
final class RevalidateScheduledStoreContentCommand extends Command
{
    public const string CACHE_KEY = 'content:store-content-schedule:last-run';

    /** First run, or a cache wipe: look back this far. */
    private const int DEFAULT_LOOKBACK_MINUTES = 10;

    protected $signature = 'toymart:content:revalidate-scheduled';

    protected $description = 'Purge storefront caches for banners and static pages whose schedule boundary just passed';

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
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

        $this->line(sprintf('Banners changed: %s · pages published: %d', $banners ? 'yes' : 'no', $pages->count()));

        return self::SUCCESS;
    }
}
