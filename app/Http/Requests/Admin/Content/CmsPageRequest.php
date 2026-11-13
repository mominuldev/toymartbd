<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Content;

use App\Domain\Content\DTOs\CmsPageData;
use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Enums\PageTemplate;
use App\Domain\Content\Models\CmsPage;
use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Enums\RobotsDirective;
use App\Domain\Seo\Enums\TwitterCard;
use App\Http\Requests\ApiFormRequest;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /content/pages` and `PUT /content/pages/{page}` — the whole page document. `slug` null
 * keeps the current slug (or generates one from the title). `published_at` is an ISO 8601
 * instant (the admin sends Dhaka time with its +06:00 offset); with `status: published` a
 * future instant schedules the page. Changing a live page's slug needs
 * `acknowledge_slug_redirect: true` — the old URL then 301s (slug_history).
 */
final class CmsPageRequest extends ApiFormRequest
{
    public const string SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function authorize(): bool
    {
        $page = $this->page();

        return $page === null
            ? ($this->user()?->can('create', CmsPage::class) ?? false)
            : ($this->user()?->can('update', $page) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $page = $this->page();

        return [
            'title' => ['required', 'string', 'max:191'],
            'title_bn' => ['nullable', 'string', 'max:191'],
            'slug' => [
                'nullable', 'string', 'max:'.CmsPage::SLUG_MAX, 'regex:'.self::SLUG_PATTERN,
                Rule::unique('cms_pages', 'slug')->ignore($page?->id),
            ],
            /** Rich text (HTML from the admin editor); sanitised on save. */
            'content' => ['nullable', 'string', 'max:200000'],
            'content_bn' => ['nullable', 'string', 'max:200000'],
            'template' => ['required', Rule::enum(PageTemplate::class)],
            'status' => ['required', Rule::enum(PageStatus::class)],
            /** @format date-time */
            'published_at' => ['nullable', 'date'],
            'faq_items' => ['nullable', 'array', 'max:'.CmsPage::MAX_FAQ_ITEMS],
            'faq_items.*' => ['array:question,question_bn,answer,answer_bn'],
            'faq_items.*.question' => ['required', 'string', 'max:300'],
            'faq_items.*.question_bn' => ['nullable', 'string', 'max:300'],
            'faq_items.*.answer' => ['required', 'string', 'max:10000'],
            'faq_items.*.answer_bn' => ['nullable', 'string', 'max:10000'],

            // Search & social (seo_meta, type `page`). Omitted: keep; sent: replace.
            'seo' => ['sometimes', 'array:title,title_bn,description,description_bn,canonical_url,robots,sitemap_exclude,og_title,og_description,og_image,twitter_card,focus_keyword'],
            'seo.title' => ['nullable', 'string', 'max:191'],
            'seo.title_bn' => ['nullable', 'string', 'max:191'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.description_bn' => ['nullable', 'string', 'max:500'],
            'seo.canonical_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'seo.robots' => ['nullable', Rule::enum(RobotsDirective::class)],
            'seo.sitemap_exclude' => ['sometimes', 'boolean'],
            'seo.og_title' => ['nullable', 'string', 'max:191'],
            'seo.og_description' => ['nullable', 'string', 'max:500'],
            'seo.og_image' => ['nullable', 'string', 'max:2048', 'url:https'],
            'seo.twitter_card' => ['nullable', Rule::enum(TwitterCard::class)],
            'seo.focus_keyword' => ['nullable', 'string', 'max:120'],

            'acknowledge_slug_redirect' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $data = $validator->getData();
            $page = $this->page();

            if ($page !== null && $page->isLive() && ! $validator->errors()->has('slug')
                && is_string($data['slug'] ?? null) && $data['slug'] !== '' && $data['slug'] !== $page->slug
                && filter_var($data['acknowledge_slug_redirect'] ?? false, FILTER_VALIDATE_BOOLEAN) !== true) {
                $validator->errors()->add('slug', 'This page is live: changing its URL creates a 301 redirect from the old one. Confirm to continue.');
            }

            if (($data['template'] ?? null) === PageTemplate::Faq->value && empty($data['faq_items']) && ($data['status'] ?? null) === PageStatus::Published->value) {
                $validator->errors()->add('faq_items', 'An FAQ page needs at least one question before it is published.');
            }
        }];
    }

    public function page(): ?CmsPage
    {
        $page = $this->route('page');

        return $page instanceof CmsPage ? $page : null;
    }

    public function toData(): CmsPageData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $str = static fn (string $key): ?string => is_string($v[$key] ?? null) && trim($v[$key]) !== '' ? $v[$key] : null;

        $faq = null;
        if (is_array($v['faq_items'] ?? null)) {
            $faq = [];
            foreach ($v['faq_items'] as $item) {
                $faq[] = [
                    'question' => (string) $item['question'],
                    'question_bn' => is_string($item['question_bn'] ?? null) ? $item['question_bn'] : null,
                    'answer' => (string) $item['answer'],
                    'answer_bn' => is_string($item['answer_bn'] ?? null) ? $item['answer_bn'] : null,
                ];
            }
        }

        return new CmsPageData(
            title: (string) $v['title'],
            titleBn: $str('title_bn'),
            slug: $str('slug'),
            content: $str('content'),
            contentBn: $str('content_bn'),
            faqItems: $faq,
            template: PageTemplate::from((string) $v['template']),
            status: PageStatus::from((string) $v['status']),
            publishedAt: is_string($v['published_at'] ?? null) ? CarbonImmutable::parse($v['published_at'])->utc() : null,
        );
    }

    public function seoData(): ?SeoMetaData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();

        if (! is_array($v['seo'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $seo */
        $seo = $v['seo'];
        $text = static fn (string $key): ?string => is_string($seo[$key] ?? null) && trim($seo[$key]) !== '' ? trim($seo[$key]) : null;

        return new SeoMetaData(
            title: $text('title'),
            titleBn: $text('title_bn'),
            description: $text('description'),
            descriptionBn: $text('description_bn'),
            canonicalUrl: $text('canonical_url'),
            robots: is_string($seo['robots'] ?? null) ? RobotsDirective::from($seo['robots']) : RobotsDirective::IndexFollow,
            sitemapExclude: (bool) ($seo['sitemap_exclude'] ?? false),
            ogTitle: $text('og_title'),
            ogDescription: $text('og_description'),
            ogImage: $text('og_image'),
            twitterCard: is_string($seo['twitter_card'] ?? null) ? TwitterCard::from($seo['twitter_card']) : TwitterCard::SummaryLargeImage,
            focusKeyword: $text('focus_keyword'),
        );
    }
}
