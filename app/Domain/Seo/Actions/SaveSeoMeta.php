<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Services\StoreContentTags;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\SeoMeta;
use Illuminate\Support\Facades\DB;

/**
 * Save the whole `seo_meta` document of one page, any type (the per-entity SeoPanel).
 * A save replaces every column, Bangla Open Graph included. The page's storefront cache
 * (`{type}:{slug}` and `seo`) is purged after commit.
 */
final class SaveSeoMeta
{
    public function handle(SeoSubject $subject, SeoMetaData $data): SeoMeta
    {
        $meta = DB::transaction(static fn (): SeoMeta => SeoMeta::query()->updateOrCreate(
            ['seoable_type' => $subject->type, 'seoable_id' => $subject->id],
            [
                'title' => $data->title,
                'title_bn' => $data->titleBn,
                'description' => $data->description,
                'description_bn' => $data->descriptionBn,
                'canonical_url' => $data->canonicalUrl,
                'robots' => $data->robots,
                'sitemap_exclude' => $data->sitemapExclude,
                'og_title' => $data->ogTitle,
                'og_description' => $data->ogDescription,
                'og_title_bn' => $data->ogTitleBn,
                'og_description_bn' => $data->ogDescriptionBn,
                'og_image' => $data->ogImage,
                'twitter_card' => $data->twitterCard,
                'schema_override' => $data->schemaOverride,
                'focus_keyword' => $data->focusKeyword,
            ],
        ));

        if ($meta->wasRecentlyCreated || $meta->wasChanged()) {
            $tags = ['seo', $subject->type.':'.$subject->slug];
            if ($subject->type === 'blog_post') {
                $tags[] = 'post:'.$subject->slug; // the blog's own page tag
            }
            if ($subject->type === CmsPage::MORPH) {
                // The page payload carries its SEO defaults too (`page.seo`): purge the content fetch.
                $tags = [...$tags, StoreContentTags::PAGES, ...StoreContentTags::pageAll($subject->slug)];
            }
            $tags = array_values(array_unique($tags));
            SeoPagesStale::dispatch($tags);
        }

        return $meta;
    }
}
