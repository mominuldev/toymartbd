<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Catalog\Models\Product;
use App\Domain\Seo\Models\SeoMeta;
use App\Domain\Seo\Services\ProductSeo;
use App\Domain\Seo\Services\SerpMeasure;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A product's "Search & social" block (SeoPanel 198:1123): the stored `seo_meta`
 * overrides (null when unset), the defaults an empty override falls back to, the
 * server's pixel measurement of the effective title and description, the SEO
 * checklist and the effective Product JSON-LD. Everything derived is computed here,
 * on the server (rule 1).
 *
 * @property Product $resource
 */
#[SchemaName('ProductSeo')]
final class ProductSeoResource extends ApiResource
{
    public function __construct(Product $product, private readonly ?SeoMeta $meta)
    {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;
        $meta = $this->meta;
        $seo = app(ProductSeo::class);
        $measure = app(SerpMeasure::class);
        $defaults = $seo->defaults($product);
        $title = ProductSeo::effective($meta?->title, $defaults['title']);
        $description = ProductSeo::effective($meta?->description, $defaults['description']);

        return [
            /** @var string|null */
            'title' => $meta?->title,
            /** @var string|null */
            'title_bn' => $meta?->title_bn,
            /** @var string|null */
            'description' => $meta?->description,
            /** @var string|null */
            'description_bn' => $meta?->description_bn,
            /** @var string|null */
            'canonical_url' => $meta?->canonical_url,
            /** @var 'index,follow'|'noindex,follow'|'index,nofollow'|'noindex,nofollow' */
            'robots' => $meta === null ? 'index,follow' : $meta->robots->value,
            'sitemap_exclude' => $meta !== null && $meta->sitemap_exclude,
            /** @var string|null */
            'og_title' => $meta?->og_title,
            /** @var string|null */
            'og_description' => $meta?->og_description,
            /** @var string|null */
            'og_image' => $meta?->og_image,
            /** @var 'summary_large_image'|'summary' */
            'twitter_card' => $meta === null ? 'summary_large_image' : $meta->twitter_card->value,
            /** @var array<string, mixed>|null */
            'schema_override' => $meta?->schema_override,
            /** @var string|null */
            'focus_keyword' => $meta?->focus_keyword,
            /**
             * What an empty override falls back to.
             *
             * @var array{title: string, title_bn: string, description: string, description_bn: string, og_image: string|null}
             */
            'defaults' => $defaults,
            /** The canonical storefront URL of the product. */
            'url' => $seo->url($product),
            /**
             * Estimated rendered widths of the effective title and description at Google's
             * desktop metric (see POST /products/seo-measure for unsaved text).
             *
             * @var array{title: array{px: int, chars: int, max: int, fits: bool}, description: array{px: int, chars: int, max: int, fits: bool, fits_mobile: bool}}
             */
            'measure' => ['title' => $measure->title($title), 'description' => $measure->description($description)],
            /**
             * The saved product's SEO checklist. `key` ∈ title_fits, description_fits,
             * primary_image, image_alt, slug, product_schema, sitemap.
             *
             * @var array{passed: int, total: int, items: list<array{key: string, status: 'pass'|'warn'|'fail', params: array<string, int|string>}>}
             */
            'checklist' => $seo->checklist($product, $meta),
            /**
             * The effective Product JSON-LD (generated, with `schema_override` merged on top).
             *
             * @var array<string, mixed>
             */
            'schema' => $seo->schema($product, $meta),
        ];
    }
}
