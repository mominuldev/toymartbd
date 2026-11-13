<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Blog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogPostProduct;
use App\Domain\Content\Blog\Models\BlogPostSection;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Content\Blog\Services\BlogHtml;
use App\Domain\Content\Blog\Services\BlogProductPicks;
use App\Domain\Content\Blog\Services\RelatedPosts;
use App\Domain\Seo\Models\SeoMeta;
use App\Http\Resources\Admin\Blog\BlogImage;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Storefront\StorefrontProductCardResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One blog post or buying guide page. Body HTML is re-sanitised on read. Guide picks and
 * manual related products are product cards with live price and stock; a pick whose product
 * is out of stock, archived or unpublished is omitted, and a section whose picks are all
 * hidden keeps its heading and text but shows no cards. `related_posts` share the category
 * or tags (else the most recent). `seo` holds the per-locale overrides (null = fall back).
 * `modified_at` feeds Article `dateModified`.
 *
 * @property BlogPost $resource
 */
#[SchemaName('StorefrontBlogPost')]
final class StorefrontBlogPostResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = $this->resource;
        $post->loadMissing(['category', 'author.avatar', 'cover', 'tags', 'sections.picks', 'relatedProductLinks']);

        $productIds = $post->productLinks()->pluck('product_id')->map(intval(...))->all();
        $visible = app(BlogProductPicks::class)->visible($productIds);
        $seo = SeoMeta::query()->where('seoable_type', BlogPost::SEO_TYPE)->where('seoable_id', $post->id)->first();

        $cards = static function (iterable $links) use ($request, $visible): array {
            $out = [];
            foreach ($links as $link) {
                /** @var BlogPostProduct $link */
                $product = $visible[$link->product_id] ?? null;
                if (! $product instanceof Product) {
                    continue;
                }
                $out[] = [
                    'note' => $link->note,
                    'note_bn' => $link->note_bn,
                    'product' => (new StorefrontProductCardResource($product))->toArray($request),
                ];
            }

            return $out;
        };

        $card = (new StorefrontBlogPostCardResource($post))->toArray($request);
        $author = $post->author;

        return [
            ...$card,
            /** @var string|null */
            'body' => BlogHtml::sanitize($post->body),
            /** @var string|null */
            'body_bn' => BlogHtml::sanitize($post->body_bn),
            /** @var list<array{name: string, name_bn: string|null, slug: string}> */
            'tags' => $post->tags->map(static fn (BlogTag $t): array => ['name' => $t->name, 'name_bn' => $t->name_bn, 'slug' => $t->slug])->values()->all(),
            /** @var array{name: string, name_bn: string|null, slug: string, bio: string|null, bio_bn: string|null, avatar: array{media_id: int, url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null}|null */
            'author' => $author === null ? null : [
                'name' => $author->name,
                'name_bn' => $author->name_bn,
                'slug' => $author->slug,
                'bio' => $author->bio,
                'bio_bn' => $author->bio_bn,
                'avatar' => BlogImage::of($author->avatar, 320),
            ],
            /** @var string|null */
            'guide_age_band' => $post->guide_age_band?->value,
            /** @var string|null */
            'guide_budget_min' => $post->guide_budget_min?->toDecimal(),
            /** @var string|null */
            'guide_budget_max' => $post->guide_budget_max?->toDecimal(),
            /** @var list<array{heading: string, heading_bn: string|null, body: string|null, body_bn: string|null, age_band: string|null, budget_min: string|null, budget_max: string|null, picks: list<array{note: string|null, note_bn: string|null, product: array<string, mixed>}>}> */
            'sections' => $post->sections->map(static fn (BlogPostSection $s): array => [
                'heading' => $s->heading,
                'heading_bn' => $s->heading_bn,
                'body' => BlogHtml::sanitize($s->body),
                'body_bn' => BlogHtml::sanitize($s->body_bn),
                'age_band' => $s->age_band?->value,
                'budget_min' => $s->budget_min?->toDecimal(),
                'budget_max' => $s->budget_max?->toDecimal(),
                'picks' => $cards($s->picks),
            ])->values()->all(),
            /** @var list<array{note: string|null, note_bn: string|null, product: array<string, mixed>}> */
            'related_products' => $cards($post->relatedProductLinks),
            /** @var list<array<string, mixed>> */
            'related_posts' => StorefrontBlogPostCardResource::collection(app(RelatedPosts::class)->for($post))->toArray($request),
            /** @var array{title: string|null, title_bn: string|null, description: string|null, description_bn: string|null, canonical_url: string|null, robots: string, og_image: string|null} */
            'seo' => [
                'title' => $seo?->title,
                'title_bn' => $seo?->title_bn,
                'description' => $seo?->description,
                'description_bn' => $seo?->description_bn,
                'canonical_url' => $seo?->canonical_url,
                'robots' => $seo?->robots->value ?? 'index,follow',
                'og_image' => $seo?->og_image,
            ],
            /** @var string|null */
            'modified_at' => $post->updated_at?->toIso8601String(),
        ];
    }
}
