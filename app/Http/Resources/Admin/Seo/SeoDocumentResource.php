<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Seo;

use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\SeoDocuments;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One page's SEO document for the reusable SeoPanel (docs/api/seo.md §2): the stored
 * overrides (null = unset), the server defaults, both-locale SERP previews with the
 * server's pixel truncation, and the page URLs.
 *
 * @property SeoSubject $resource
 */
#[SchemaName('SeoDocument')]
final class SeoDocumentResource extends ApiResource
{
    public function __construct(SeoSubject $subject, private readonly ?SeoMeta $meta)
    {
        parent::__construct($subject);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $subject = $this->resource;
        $meta = $this->meta;
        $docs = app(SeoDocuments::class);

        return [
            'type' => $subject->type,
            'id' => $subject->id,
            'slug' => $subject->slug,
            'path' => $subject->path,
            'is_live' => $subject->isLive,
            /** @var array{en: string, bn: string} */
            'urls' => $docs->urls($subject),
            /** @var string|null */
            'title' => $meta?->title,
            /** @var string|null */
            'title_bn' => $meta?->title_bn,
            /** @var string|null */
            'description' => $meta?->description,
            /** @var string|null */
            'description_bn' => $meta?->description_bn,
            /** @var string|null */
            'og_title' => $meta?->og_title,
            /** @var string|null */
            'og_title_bn' => $meta?->og_title_bn,
            /** @var string|null */
            'og_description' => $meta?->og_description,
            /** @var string|null */
            'og_description_bn' => $meta?->og_description_bn,
            /** @var string|null */
            'og_image' => $meta?->og_image,
            /** @var string|null */
            'canonical_url' => $meta?->canonical_url,
            /** @var 'index,follow'|'noindex,follow'|'index,nofollow'|'noindex,nofollow' */
            'robots' => $docs->robots($meta)->value,
            'sitemap_exclude' => $meta !== null && $meta->sitemap_exclude,
            /** @var 'summary_large_image'|'summary' */
            'twitter_card' => $meta === null ? 'summary_large_image' : $meta->twitter_card->value,
            /** @var string|null */
            'focus_keyword' => $meta?->focus_keyword,
            /** @var array{title: string, title_bn: string, description: string, description_bn: string, og_image: string|null} */
            'defaults' => $docs->defaults($subject),
            /**
             * Search-result previews, computed on the server.
             *
             * @var array{en: array{title: string, title_display: string, title_px: int, title_max: int, title_truncated: bool, description: string, description_display: string, description_px: int, description_max: int, description_truncated: bool, url_display: string}, bn: array{title: string, title_display: string, title_px: int, title_max: int, title_truncated: bool, description: string, description_display: string, description_px: int, description_max: int, description_truncated: bool, url_display: string}}
             */
            'serp' => $docs->serp($subject, $meta),
            'in_sitemap' => $docs->inSitemap($subject, $meta),
        ];
    }
}
