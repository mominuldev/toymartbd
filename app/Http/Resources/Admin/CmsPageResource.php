<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Services\ProductDescription;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\SiteTitle;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Content\ContentFormat;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A static page in the admin. `state` is derived on the server: draft | scheduled | live |
 * archived. `content`, `faq_items` and `seo` are included on the single-page endpoints only
 * (null in the list).
 *
 * @property CmsPage $resource
 */
#[SchemaName('CmsPage')]
final class CmsPageResource extends ApiResource
{
    public function __construct(CmsPage $page, private readonly bool $full = false)
    {
        parent::__construct($page);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $page = $this->resource;
        $meta = $this->full ? SeoMeta::query()->where('seoable_type', CmsPage::MORPH)->where('seoable_id', $page->id)->first() : null;
        $summary = ProductDescription::plainText($page->content);

        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'title' => $page->title,
            /** @var string|null */
            'title_bn' => $page->title_bn,
            /** @var 'default'|'sidebar'|'faq' */
            'template' => $page->template->value,
            /** @var 'draft'|'published'|'archived' */
            'status' => $page->status->value,
            /** @var 'draft'|'scheduled'|'live'|'archived' */
            'state' => self::state($page),
            /** @var string|null */
            'published_at' => ContentFormat::utc($page->published_at),
            /** Locale-free storefront path. */
            'path' => '/pages/'.$page->slug,
            'has_bangla' => ($page->title_bn ?? '') !== '' && (($page->content_bn ?? '') !== '' || $page->faq_items !== null),
            /** @var string|null */
            'content' => $this->full ? $page->content : null,
            /** @var string|null */
            'content_bn' => $this->full ? $page->content_bn : null,
            /** @var list<array{question: string, question_bn: string|null, answer: string, answer_bn: string|null}>|null */
            'faq_items' => $this->full ? $page->faq_items : null,
            /**
             * Stored seo_meta overrides plus the defaults an empty field falls back to.
             *
             * @var array{title: string|null, title_bn: string|null, description: string|null, description_bn: string|null, canonical_url: string|null, robots: string, sitemap_exclude: bool, og_title: string|null, og_description: string|null, og_image: string|null, twitter_card: string, focus_keyword: string|null, defaults: array{title: string, title_bn: string, description: string}}|null
             */
            'seo' => $this->full ? [
                'title' => $meta?->title,
                'title_bn' => $meta?->title_bn,
                'description' => $meta?->description,
                'description_bn' => $meta?->description_bn,
                'canonical_url' => $meta?->canonical_url,
                'robots' => $meta === null ? 'index,follow' : $meta->robots->value,
                'sitemap_exclude' => $meta !== null && $meta->sitemap_exclude,
                'og_title' => $meta?->og_title,
                'og_description' => $meta?->og_description,
                'og_image' => $meta?->og_image,
                'twitter_card' => $meta === null ? 'summary_large_image' : $meta->twitter_card->value,
                'focus_keyword' => $meta?->focus_keyword,
                'defaults' => [
                    'title' => SiteTitle::of($page->title),
                    'title_bn' => SiteTitle::of($page->title_bn ?? $page->title),
                    'description' => mb_strlen($summary) > 155 ? rtrim(mb_substr($summary, 0, 154)).'…' : $summary,
                ],
            ] : null,
            /** @var string|null */
            'updated_at' => ContentFormat::utc($page->updated_at),
            /** @var string|null */
            'created_at' => ContentFormat::utc($page->created_at),
        ];
    }

    public static function state(CmsPage $page): string
    {
        return match (true) {
            $page->status === PageStatus::Archived => 'archived',
            $page->isLive() => 'live',
            $page->isScheduled() => 'scheduled',
            default => 'draft',
        };
    }
}
