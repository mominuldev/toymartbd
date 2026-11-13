<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Seo;

use App\Domain\Seo\Contracts\SeoSubject;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\SeoDocuments;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The effective SEO of one live page for the storefront `<head>`, both locales
 * (docs/api/seo.md §3). Every text is already resolved (override, else default);
 * `title` is a complete `<title>`.
 *
 * @property SeoSubject $resource
 */
#[SchemaName('StorefrontSeo')]
final class StorefrontSeoResource extends ApiResource
{
    public function __construct(SeoSubject $subject, private readonly ?SeoMeta $meta)
    {
        parent::__construct($subject);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $subject = $this->resource;
        $docs = app(SeoDocuments::class);
        $robots = $docs->robots($this->meta);
        $effective = $docs->effective($subject, $this->meta);
        $defaults = $docs->defaults($subject);

        return [
            'type' => $subject->type,
            'slug' => $subject->slug,
            'path' => $subject->path,
            /** @var array{index: bool, follow: bool} */
            'robots' => ['index' => $robots->indexable(), 'follow' => str_ends_with($robots->value, ',follow')],
            /** @var string|null */
            'canonical_url' => $this->meta?->canonical_url,
            'sitemap_exclude' => $this->meta !== null && $this->meta->sitemap_exclude,
            /** @var 'summary_large_image'|'summary' */
            'twitter_card' => $this->meta === null ? 'summary_large_image' : $this->meta->twitter_card->value,
            /** @var string|null */
            'og_image' => $this->meta->og_image ?? $defaults['og_image'],
            /** @var array{title: string, description: string, og_title: string, og_description: string} */
            'en' => $effective['en'],
            /** @var array{title: string, description: string, og_title: string, og_description: string} */
            'bn' => $effective['bn'],
            /** @var string|null */
            'updated_at' => $subject->updatedAt?->toAtomString(),
        ];
    }
}
