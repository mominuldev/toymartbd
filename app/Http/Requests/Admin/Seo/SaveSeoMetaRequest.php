<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Seo;

use App\Domain\Seo\DTOs\SeoMetaData;
use App\Domain\Seo\Enums\RobotsDirective;
use App\Domain\Seo\Enums\TwitterCard;
use App\Domain\Seo\Models\SeoMeta;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /seo/meta/{type}/{id}` — the whole SEO document of one page (SeoPanel). Every text
 * field is optional (empty = the server default); a save replaces the stored document.
 */
final class SaveSeoMetaRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', SeoMeta::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:191'],
            'title_bn' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
            'description_bn' => ['nullable', 'string', 'max:500'],
            'og_title' => ['nullable', 'string', 'max:191'],
            'og_title_bn' => ['nullable', 'string', 'max:191'],
            'og_description' => ['nullable', 'string', 'max:500'],
            'og_description_bn' => ['nullable', 'string', 'max:500'],
            'og_image' => ['nullable', 'string', 'max:2048', 'url:https'],
            'canonical_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'robots' => ['sometimes', 'nullable', Rule::enum(RobotsDirective::class)],
            'sitemap_exclude' => ['sometimes', 'boolean'],
            'twitter_card' => ['sometimes', 'nullable', Rule::enum(TwitterCard::class)],
            'focus_keyword' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function toData(): SeoMetaData
    {
        $text = function (string $key): ?string {
            $value = $this->validated($key);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $robots = $this->validated('robots');
        $card = $this->validated('twitter_card');

        return new SeoMetaData(
            title: $text('title'),
            titleBn: $text('title_bn'),
            description: $text('description'),
            descriptionBn: $text('description_bn'),
            canonicalUrl: $text('canonical_url'),
            robots: is_string($robots) ? RobotsDirective::from($robots) : RobotsDirective::IndexFollow,
            sitemapExclude: (bool) $this->validated('sitemap_exclude', false),
            ogTitle: $text('og_title'),
            ogDescription: $text('og_description'),
            ogImage: $text('og_image'),
            twitterCard: is_string($card) ? TwitterCard::from($card) : TwitterCard::SummaryLargeImage,
            focusKeyword: $text('focus_keyword'),
            ogTitleBn: $text('og_title_bn'),
            ogDescriptionBn: $text('og_description_bn'),
        );
    }
}
