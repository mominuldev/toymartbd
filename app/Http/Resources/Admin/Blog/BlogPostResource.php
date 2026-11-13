<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Blog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Blog\Models\BlogPostProduct;
use App\Domain\Content\Blog\Models\BlogPostSection;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Domain\Content\Blog\Services\BlogProductPicks;
use App\Domain\Seo\Models\SeoMeta;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The full post document for the admin editor. Each linked product carries `visible`: false
 * when the storefront hides it (out of stock, archived or unpublished), so the editor can warn.
 * `seo` is the saved seo_meta (null fields fall back to the title and excerpt on the storefront).
 * `checklist` drives the editor's Search & social panel.
 *
 * @property BlogPost $resource
 */
#[SchemaName('AdminBlogPost')]
final class BlogPostResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = $this->resource;
        $post->loadMissing(['category', 'author', 'cover', 'tags', 'sections.picks', 'relatedProductLinks']);

        $productIds = $post->productLinks()->pluck('product_id')->map(intval(...))->all();
        $products = Product::query()->withTrashed()->whereIn('id', $productIds)->get()->keyBy('id');
        $visible = app(BlogProductPicks::class)->visible($productIds);
        $seo = SeoMeta::query()->where('seoable_type', BlogPost::SEO_TYPE)->where('seoable_id', $post->id)->first();

        $link = static function (BlogPostProduct $row) use ($products, $visible): array {
            $product = $products->get($row->product_id);
            $known = $product instanceof Product;

            return [
                'product_id' => $row->product_id,
                'name' => $known ? $product->name : '',
                'name_bn' => $known ? $product->name_bn : null,
                'slug' => $known ? $product->slug : '',
                'note' => $row->note,
                'note_bn' => $row->note_bn,
                'visible' => isset($visible[$row->product_id]),
            ];
        };

        return [
            'id' => $post->id,
            'type' => $post->type->value,
            'slug' => $post->slug,
            'title' => $post->title,
            /** @var string|null */
            'title_bn' => $post->title_bn,
            /** @var string|null */
            'excerpt' => $post->excerpt,
            /** @var string|null */
            'excerpt_bn' => $post->excerpt_bn,
            /** @var string|null */
            'body' => $post->body,
            /** @var string|null */
            'body_bn' => $post->body_bn,
            /** @var array{media_id: int, url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null */
            'cover' => BlogImage::of($post->cover),
            /** @var int|null */
            'author_id' => $post->author_id,
            /** @var array{id: int, name: string, name_bn: string|null}|null */
            'author' => $post->author === null ? null : ['id' => $post->author->id, 'name' => $post->author->name, 'name_bn' => $post->author->name_bn],
            /** @var int|null */
            'category_id' => $post->category_id,
            /** @var array{id: int, name: string, name_bn: string|null}|null */
            'category' => $post->category === null ? null : ['id' => $post->category->id, 'name' => $post->category->name, 'name_bn' => $post->category->name_bn],
            /** @var list<array{id: int, name: string, name_bn: string|null, slug: string}> */
            'tags' => $post->tags->map(static fn (BlogTag $t): array => ['id' => $t->id, 'name' => $t->name, 'name_bn' => $t->name_bn, 'slug' => $t->slug])->values()->all(),
            'status' => $post->status->value,
            /** @var bool */
            'is_live' => $post->isLive(),
            /** @var string|null */
            'published_at' => $post->published_at?->toIso8601String(),
            /** @var string|null */
            'published_at_dhaka' => $post->published_at === null ? null : DhakaDate::local($post->published_at)->format('Y-m-d H:i'),
            'is_featured' => $post->is_featured,
            'reading_minutes' => $post->reading_minutes,
            /** @var int|null */
            'reading_minutes_bn' => $post->reading_minutes_bn,
            /** @var string|null */
            'guide_age_band' => $post->guide_age_band?->value,
            /** @var string|null */
            'guide_budget_min' => $post->guide_budget_min?->toDecimal(),
            /** @var string|null */
            'guide_budget_max' => $post->guide_budget_max?->toDecimal(),
            /** @var list<array{id: int, heading: string, heading_bn: string|null, body: string|null, body_bn: string|null, age_band: string|null, budget_min: string|null, budget_max: string|null, picks: list<array{product_id: int, name: string, name_bn: string|null, slug: string, note: string|null, note_bn: string|null, visible: bool}>}> */
            'sections' => $post->sections->map(static fn (BlogPostSection $s): array => [
                'id' => $s->id,
                'heading' => $s->heading,
                'heading_bn' => $s->heading_bn,
                'body' => $s->body,
                'body_bn' => $s->body_bn,
                'age_band' => $s->age_band?->value,
                'budget_min' => $s->budget_min?->toDecimal(),
                'budget_max' => $s->budget_max?->toDecimal(),
                'picks' => $s->picks->map($link)->values()->all(),
            ])->values()->all(),
            /** @var list<array{product_id: int, name: string, name_bn: string|null, slug: string, note: string|null, note_bn: string|null, visible: bool}> */
            'related_products' => $post->relatedProductLinks->map($link)->values()->all(),
            /** @var array{title: string|null, title_bn: string|null, description: string|null, description_bn: string|null, canonical_url: string|null, robots: string, og_image: string|null, focus_keyword: string|null} */
            'seo' => [
                'title' => $seo?->title,
                'title_bn' => $seo?->title_bn,
                'description' => $seo?->description,
                'description_bn' => $seo?->description_bn,
                'canonical_url' => $seo?->canonical_url,
                'robots' => $seo?->robots->value ?? 'index,follow',
                'og_image' => $seo?->og_image,
                'focus_keyword' => $seo?->focus_keyword,
            ],
            /** @var array{has_bangla: bool, cover_alt: bool, cover_alt_bn: bool, product_links: int} */
            'checklist' => [
                'has_bangla' => $post->title_bn !== null && $post->body_bn !== null,
                'cover_alt' => self::filled($post->cover?->alt_text),
                'cover_alt_bn' => self::filled($post->cover?->alt_text_bn),
                'product_links' => count(array_unique($productIds)),
            ],
            /** @var string|null */
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    private static function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
