<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Services\ProductDescription;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\Models\SeoMeta;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Content\ContentFormat;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A live static page for the storefront. HTML is re-sanitised on the way out, so a row written
 * around the editor (seeder, SQL) never reaches a page unfiltered. `seo` carries the effective
 * per-locale title and description (an empty override falls back to the page's own), robots
 * and social fields; the Next.js metadata builder reads it.
 *
 * @property CmsPage $resource
 */
#[SchemaName('StorefrontPage')]
final class StorefrontPageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $page = $this->resource;
        $meta = SeoMeta::query()->where('seoable_type', CmsPage::MORPH)->where('seoable_id', $page->id)->first();
        $summary = self::summary(ProductDescription::plainText($page->content));
        $summaryBn = self::summary(ProductDescription::plainText($page->content_bn)) ?: $summary;
        $pick = static fn (?string $override, string $default): string => $override !== null && $override !== '' ? $override : $default;

        return [
            'slug' => $page->slug,
            'title' => $page->title,
            /** @var string|null */
            'title_bn' => $page->title_bn,
            /** @var 'default'|'sidebar'|'faq' */
            'template' => $page->template->value,
            /** @var string|null */
            'content' => ProductDescription::html($page->content),
            /** @var string|null */
            'content_bn' => ProductDescription::html($page->content_bn),
            /** @var list<array{question: string, question_bn: string|null, answer: string, answer_bn: string|null}> */
            'faq_items' => array_map(static fn (array $i): array => [
                'question' => $i['question'],
                'question_bn' => $i['question_bn'] ?? null,
                'answer' => (string) ProductDescription::html($i['answer']),
                'answer_bn' => ProductDescription::html($i['answer_bn'] ?? null),
            ], $page->faq_items ?? []),
            /** @var string|null */
            'published_at' => ContentFormat::utc($page->published_at),
            /** @var string|null */
            'updated_at' => ContentFormat::utc($page->updated_at),
            /**
             * @var array{title: string, title_bn: string, description: string, description_bn: string, canonical_url: string|null, robots: string, og_title: string|null, og_description: string|null, og_image: string|null, twitter_card: string}
             */
            'seo' => [
                'title' => $pick($meta?->title, $page->title),
                'title_bn' => $pick($meta?->title_bn, $page->title_bn ?? $page->title),
                'description' => $pick($meta?->description, $summary),
                'description_bn' => $pick($meta?->description_bn, $summaryBn),
                'canonical_url' => $meta?->canonical_url,
                'robots' => $meta === null ? 'index,follow' : $meta->robots->value,
                'og_title' => $meta?->og_title,
                'og_description' => $meta?->og_description,
                'og_image' => $meta?->og_image,
                'twitter_card' => $meta === null ? 'summary_large_image' : $meta->twitter_card->value,
            ],
        ];
    }

    private static function summary(string $text): string
    {
        return mb_strlen($text) > 155 ? rtrim(mb_substr($text, 0, 154)).'…' : $text;
    }
}
