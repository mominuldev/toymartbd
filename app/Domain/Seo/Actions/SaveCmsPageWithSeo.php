<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Models\SlugHistory;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Save a static page together with its `seo_meta` and, when a live page's slug changes, its
 * `slug_history` row — one transaction, exactly as {@see SaveProductWithSeo} does for products
 * (morph key `page`). The page write is Content's SaveCmsPage, passed as a closure (Seo may
 * depend on Content, never the reverse); its transaction nests as a savepoint.
 *
 * - `$seo` null: leave the page's seo_meta as it is.
 * - A slug change is recorded only when the old URL was live. A slug that becomes live again
 *   stops being a redirect, and earlier rows are re-pointed so a redirect is always one hop.
 */
final class SaveCmsPageWithSeo
{
    /** @param  Closure(): CmsPage  $write */
    public function handle(?CmsPage $existing, Closure $write, ?SeoMetaData $seo, ?int $actorId = null): CmsPage
    {
        return DB::transaction(function () use ($existing, $write, $seo, $actorId): CmsPage {
            $previousSlug = $existing?->slug;
            $wasLive = $existing?->isLive() ?? false;

            $page = $write();

            if ($previousSlug !== null && $previousSlug !== $page->slug) {
                SlugHistory::query()
                    ->where('sluggable_type', CmsPage::MORPH)
                    ->where('old_slug', $page->slug)
                    ->delete();

                if ($wasLive) {
                    SlugHistory::query()->create([
                        'sluggable_type' => CmsPage::MORPH,
                        'sluggable_id' => $page->id,
                        'old_slug' => $previousSlug,
                        'new_slug' => $page->slug,
                        'user_id' => $actorId,
                    ]);

                    SlugHistory::query()
                        ->where('sluggable_type', CmsPage::MORPH)
                        ->where('sluggable_id', $page->id)
                        ->update(['new_slug' => $page->slug]);
                }
            }

            if ($seo !== null) {
                $meta = SeoMeta::query()->updateOrCreate(
                    ['seoable_type' => CmsPage::MORPH, 'seoable_id' => $page->id],
                    [
                        'title' => $seo->title,
                        'title_bn' => $seo->titleBn,
                        'description' => $seo->description,
                        'description_bn' => $seo->descriptionBn,
                        'canonical_url' => $seo->canonicalUrl,
                        'robots' => $seo->robots,
                        'sitemap_exclude' => $seo->sitemapExclude,
                        'og_title' => $seo->ogTitle,
                        'og_description' => $seo->ogDescription,
                        'og_image' => $seo->ogImage,
                        'twitter_card' => $seo->twitterCard,
                        'schema_override' => $seo->schemaOverride,
                        'focus_keyword' => $seo->focusKeyword,
                    ],
                );

                // The storefront's <head> reads it: purge the page (after commit).
                if ($meta->wasRecentlyCreated || $meta->wasChanged()) {
                    CmsPageChanged::dispatch($page->id, $page->slug);
                }
            }

            return $page;
        });
    }
}
