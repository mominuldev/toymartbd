<?php

declare(strict_types=1);

namespace App\Domain\Seo\Actions;

use App\Domain\Content\Models\CmsPage;
use App\Domain\Content\Services\StoreContentTags;
use App\Domain\Seo\Contracts\SeoMetaStore;
use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Events\SeoPagesStale;
use App\Domain\Seo\Models\SeoMeta;
use Illuminate\Support\Facades\DB;

/**
 * The ONE write path to `seo_meta` (DATABASE-DESIGN §3.9), for every page type:
 *
 * - {@see handle()} — the per-entity SeoPanel: the whole document is replaced, Bangla Open
 *   Graph included, and the page's storefront cache (`{type}:{slug}` and `seo`) is purged
 *   after commit.
 * - {@see write()} — a document saved together with its page (product, static page): only
 *   the given columns; the caller purges its own page.
 * - {@see merge()} / {@see forget()} — the {@see SeoMetaStore} contract for domains that may
 *   not depend on Seo (the blog editor).
 *
 * Each joins the caller's transaction as a savepoint.
 */
final class SaveSeoMeta implements SeoMetaStore
{
    /** Every column a document carries. */
    public const array COLUMNS = [
        'title', 'title_bn', 'description', 'description_bn', 'canonical_url', 'robots', 'sitemap_exclude',
        'og_title', 'og_description', 'og_title_bn', 'og_description_bn', 'og_image', 'twitter_card', 'schema_override', 'focus_keyword',
    ];

    public function handle(SeoSubject $subject, SeoMetaData $data): SeoMeta
    {
        $meta = $this->write($subject->type, $subject->id, self::columns($data));

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

    /**
     * Upsert these columns of one page's row; other columns keep their value.
     *
     * @param  array<string, mixed>  $columns
     */
    public function write(string $type, int $id, array $columns): SeoMeta
    {
        $columns = array_intersect_key($columns, array_flip(self::COLUMNS));

        return DB::transaction(static function () use ($type, $id, $columns): SeoMeta {
            $meta = SeoMeta::query()->firstOrNew(['seoable_type' => $type, 'seoable_id' => $id]);
            $meta->fill($columns);
            $meta->save();

            return $meta;
        });
    }

    public function merge(string $type, int $id, array $columns): void
    {
        $this->write($type, $id, $columns);
    }

    public function forget(string $type, int $id): void
    {
        DB::transaction(static fn () => SeoMeta::for($type, $id)->delete());
    }

    /** @return array<string, mixed> the whole document as columns */
    public static function columns(SeoMetaData $data): array
    {
        return [
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
        ];
    }
}
