<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Blog;

use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Content\Blog\DTOs\BlogPostData;
use App\Domain\Content\Blog\DTOs\GuidePickData;
use App\Domain\Content\Blog\DTOs\GuideSectionData;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Seo\Enums\RobotsDirective;
use App\Http\Requests\Admin\Brand\StoreBrandRequest;
use App\Http\Requests\ApiFormRequest;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /blog/posts` and `PUT /blog/posts/{post}` — the whole post document (a save replaces it).
 *
 * - `publish_at` is Dhaka wall time `Y-m-d H:i` (or ISO 8601 with an offset, the content screens'
 *   convention), required for `status: scheduled` and in the future; the server stores it as UTC.
 * - `body`/`body_bn` and section bodies are editor HTML, sanitised on save.
 * - `sections` apply to a buying guide only; each pick is a product id. `related_product_ids`
 *   are the post's manual related products (≤ 8). Picks of an unbuyable product are kept and
 *   hidden on the storefront.
 * - `seo` omitted keeps the saved seo_meta; sent, only the keys it carries change (the SEO
 *   module's full panel, `PUT /seo/meta/blog_post/{id}`, owns the rest).
 */
final class BlogPostRequest extends ApiFormRequest
{
    public const string MONEY_PATTERN = '/^\d{1,12}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $post = $this->route('post');

        return $post instanceof BlogPost
            ? ($this->user()?->can('update', $post) ?? false)
            : ($this->user()?->can('create', BlogPost::class) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $post = $this->route('post');
        $money = ['nullable', 'string', 'regex:'.self::MONEY_PATTERN];

        return [
            'type' => ['required', Rule::enum(PostType::class)],
            'title' => ['required', 'string', 'max:191'],
            'title_bn' => ['nullable', 'string', 'max:191'],
            'slug' => [
                'nullable', 'string', 'max:'.BlogPost::SLUG_MAX, 'regex:'.StoreBrandRequest::SLUG_PATTERN,
                Rule::unique('blog_posts', 'slug')->ignore($post instanceof BlogPost ? $post->id : null),
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'excerpt_bn' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:200000'],
            'body_bn' => ['nullable', 'string', 'max:200000'],
            'cover_media_id' => ['nullable', 'integer', Rule::exists('media', 'id')->where('kind', 'image')],
            'author_id' => ['nullable', 'integer', Rule::exists('blog_authors', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('blog_categories', 'id')],
            'tag_ids' => ['sometimes', 'array', 'max:20'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('blog_tags', 'id')],
            'status' => ['required', Rule::enum(PostStatus::class)],
            /** Dhaka wall time `Y-m-d H:i`, or ISO 8601 with an offset (`2026-12-01T00:00:00+06:00`). */
            'publish_at' => ['nullable', 'required_if:status,scheduled', 'date_format:Y-m-d H:i,Y-m-d\TH:i:sP'],
            'is_featured' => ['sometimes', 'boolean'],
            'guide_age_band' => ['nullable', Rule::enum(AgeBand::class)],
            'guide_budget_min' => $money,
            'guide_budget_max' => $money,
            'sections' => ['sometimes', 'array', 'max:30'],
            'sections.*.heading' => ['required', 'string', 'max:191'],
            'sections.*.heading_bn' => ['nullable', 'string', 'max:191'],
            'sections.*.body' => ['nullable', 'string', 'max:20000'],
            'sections.*.body_bn' => ['nullable', 'string', 'max:20000'],
            'sections.*.age_band' => ['nullable', Rule::enum(AgeBand::class)],
            'sections.*.budget_min' => $money,
            'sections.*.budget_max' => $money,
            'sections.*.picks' => ['sometimes', 'array', 'max:12'],
            'sections.*.picks.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'sections.*.picks.*.note' => ['nullable', 'string', 'max:500'],
            'sections.*.picks.*.note_bn' => ['nullable', 'string', 'max:500'],
            'related_product_ids' => ['sometimes', 'array', 'max:8'],
            'related_product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'seo' => ['sometimes', 'array:title,title_bn,description,description_bn,canonical_url,robots,og_image,focus_keyword'],
            'seo.title' => ['nullable', 'string', 'max:191'],
            'seo.title_bn' => ['nullable', 'string', 'max:191'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.description_bn' => ['nullable', 'string', 'max:500'],
            'seo.canonical_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'seo.robots' => ['nullable', Rule::enum(RobotsDirective::class)],
            'seo.og_image' => ['nullable', 'string', 'max:2048', 'url:https'],
            'seo.focus_keyword' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                if ($this->input('status') === PostStatus::Scheduled->value) {
                    $at = $this->publishAt();
                    if ($at === null || $at->lessThanOrEqualTo(CarbonImmutable::now())) {
                        $validator->errors()->add('publish_at', __('blog.publish_at_future'));
                    }
                }
                if (self::inverted($this->input('guide_budget_min'), $this->input('guide_budget_max'))) {
                    $validator->errors()->add('guide_budget_max', __('blog.budget_order'));
                }
                foreach ((array) $this->input('sections', []) as $i => $section) {
                    if (is_array($section) && self::inverted($section['budget_min'] ?? null, $section['budget_max'] ?? null)) {
                        $validator->errors()->add("sections.{$i}.budget_max", __('blog.budget_order'));
                    }
                }
            },
        ];
    }

    public function toData(): BlogPostData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $type = PostType::from((string) $v['type']);

        $sections = [];
        foreach ((array) ($v['sections'] ?? []) as $row) {
            /** @var array<string, mixed> $row */
            $sections[] = new GuideSectionData(
                heading: trim((string) $row['heading']),
                headingBn: self::text($row['heading_bn'] ?? null),
                body: self::text($row['body'] ?? null),
                bodyBn: self::text($row['body_bn'] ?? null),
                ageBand: is_string($row['age_band'] ?? null) ? AgeBand::from($row['age_band']) : null,
                budgetMin: self::money($row['budget_min'] ?? null),
                budgetMax: self::money($row['budget_max'] ?? null),
                picks: array_values(array_map(static fn (array $p): GuidePickData => new GuidePickData(
                    productId: (int) $p['product_id'],
                    note: self::text($p['note'] ?? null),
                    noteBn: self::text($p['note_bn'] ?? null),
                ), (array) ($row['picks'] ?? []))),
            );
        }

        return new BlogPostData(
            type: $type,
            title: trim((string) $v['title']),
            titleBn: self::text($v['title_bn'] ?? null),
            slug: self::text($v['slug'] ?? null),
            excerpt: self::text($v['excerpt'] ?? null),
            excerptBn: self::text($v['excerpt_bn'] ?? null),
            body: self::text($v['body'] ?? null),
            bodyBn: self::text($v['body_bn'] ?? null),
            coverMediaId: isset($v['cover_media_id']) ? (int) $v['cover_media_id'] : null,
            authorId: isset($v['author_id']) ? (int) $v['author_id'] : null,
            categoryId: isset($v['category_id']) ? (int) $v['category_id'] : null,
            tagIds: array_values(array_map(intval(...), (array) ($v['tag_ids'] ?? []))),
            status: PostStatus::from((string) $v['status']),
            publishAt: $this->publishAt(),
            isFeatured: (bool) ($v['is_featured'] ?? false),
            guideAgeBand: is_string($v['guide_age_band'] ?? null) ? AgeBand::from($v['guide_age_band']) : null,
            guideBudgetMin: self::money($v['guide_budget_min'] ?? null),
            guideBudgetMax: self::money($v['guide_budget_max'] ?? null),
            sections: $sections,
            relatedProductIds: array_values(array_map(intval(...), (array) ($v['related_product_ids'] ?? []))),
            seo: $this->seoData($v),
        );
    }

    /** `publish_at` (Dhaka wall time) as a UTC instant. */
    public function publishAt(): ?CarbonImmutable
    {
        $raw = $this->input('publish_at');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $at = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d H:i', $raw, DhakaDate::zone())
            : CarbonImmutable::createFromFormat('Y-m-d\TH:i:sP', $raw);

        return $at instanceof CarbonImmutable ? $at->setTimezone('UTC')->startOfMinute() : null;
    }

    /**
     * The `seo` keys that were sent, as seo_meta columns (absent keys keep their value).
     *
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>|null
     */
    private function seoData(array $v): ?array
    {
        if (! is_array($v['seo'] ?? null)) {
            return null;
        }

        $out = [];
        foreach ($v['seo'] as $key => $value) {
            $out[(string) $key] = $key === 'robots'
                ? (is_string($value) ? RobotsDirective::from($value) : RobotsDirective::IndexFollow)
                : self::text($value);
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function money(mixed $value): ?Money
    {
        return is_string($value) && $value !== '' ? Money::of($value) : null;
    }

    private static function inverted(mixed $min, mixed $max): bool
    {
        return is_string($min) && $min !== '' && is_string($max) && $max !== '' && bccomp($min, $max, 2) === 1;
    }
}
