<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Content\Events\CmsPageChanged;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\DTOs\SeoMetaData;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Save a static page together with its `seo_meta` and, when a live page's slug changes, its
 * `slug_history` row — one transaction, exactly as {@see SaveProductWithSeo} does for products
 * (morph key `page`). The page write is Content's SaveCmsPage, passed as a closure (Seo may
 * depend on Content, never the reverse); its transaction nests as a savepoint.
 *
 * Both writes go through the generic SEO path: {@see RecordSlugChange} (one hop, live slug wins,
 * only a live old URL redirects) and {@see SaveSeoMeta::write()}.
 *
 * - `$seo` null: leave the page's seo_meta as it is. The page editor carries no Bangla Open
 *   Graph fields, so those columns keep what the SEO panel saved.
 */
final class SaveCmsPageWithSeo
{
    public function __construct(private readonly RecordSlugChange $slugs, private readonly SaveSeoMeta $meta) {}

    /** @param  Closure(): CmsPage  $write */
    public function handle(?CmsPage $existing, Closure $write, ?SeoMetaData $seo, ?int $actorId = null): CmsPage
    {
        return DB::transaction(function () use ($existing, $write, $seo, $actorId): CmsPage {
            $previousSlug = $existing?->slug;
            $wasLive = $existing?->isLive() ?? false;

            $page = $write();

            if ($previousSlug !== null && $previousSlug !== $page->slug) {
                $this->slugs->handle(CmsPage::MORPH, $page->id, $previousSlug, $page->slug, $wasLive, $actorId);
            }

            if ($seo !== null) {
                $meta = $this->meta->write(CmsPage::MORPH, $page->id, SaveProductWithSeo::editorColumns($seo));

                // The storefront's <head> reads it: purge the page (after commit).
                if ($meta->wasRecentlyCreated || $meta->wasChanged()) {
                    CmsPageChanged::dispatch($page->id, $page->slug);
                }
            }

            return $page;
        });
    }
}
