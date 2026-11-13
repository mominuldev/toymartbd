<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Blocks\DTOs\ResolvedBlock;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Models\Testimonial;
use App\Http\Resources\Admin\BrandResource as AdminBrandResource;
use App\Http\Resources\ApiResource;
use App\Http\Resources\Storefront\Blog\StorefrontBlogPostCardResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The homepage as published (or, for a preview, as drafted), for one locale at one instant:
 * `{ version, published_at, preview, blocks: [{ id, type, show_mobile, show_desktop, content, … }] }`.
 *
 * `content` is single-language (Bangla falls back to English). Each type adds its data:
 * hero `content.slides[].image`, promo_split `image`, category_tiles `categories` (the
 * StorefrontCategoryNode shape), age_tiles `age_counts`, product_rail `products`
 * (StorefrontProductCard) + `see_all_href`, brand_strip `brands`, testimonials `testimonials`,
 * blog_teaser `posts` (StorefrontBlogPostCard). Blocks that would render empty are already gone. `version` is null and
 * `blocks` empty before the first publish — the storefront then shows its built-in home.
 *
 * @property array{version: int|null, published_at: string|null, preview: bool, locale: string, blocks: list<ResolvedBlock>} $resource
 */
#[SchemaName('StorefrontHome')]
final class StorefrontHomeResource extends ApiResource
{
    /** Hero art is shown up to ~608 px wide on desktop; 2× for dense screens. */
    private const int HERO_WIDTH = 1280;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $locale = $this->resource['locale'];

        return [
            /** @var int|null */
            'version' => $this->resource['version'],
            /** @var string|null */
            'published_at' => $this->resource['published_at'],
            /** @var bool */
            'preview' => $this->resource['preview'],
            /** @var list<array<string, mixed>> */
            'blocks' => array_map(fn (ResolvedBlock $block): array => $this->block($block, $locale, $request), $this->resource['blocks']),
        ];
    }

    /** @return array<string, mixed> */
    private function block(ResolvedBlock $block, string $locale, Request $request): array
    {
        $content = $block->content;
        $data = $block->data;
        $out = [
            'id' => $block->id,
            'type' => $block->type->value,
            'show_mobile' => $block->showMobile,
            'show_desktop' => $block->showDesktop,
        ];

        /** @var Collection<int, Media> $media */
        $media = $data['media'] ?? collect();

        switch ($block->type) {
            case BlockType::Hero:
                $slides = [];
                foreach (is_array($content['slides'] ?? null) ? $content['slides'] : [] as $slide) {
                    $slide['image'] = self::image($media->get($slide['media_id'] ?? 0), $slide['alt'] ?? null, $locale);
                    unset($slide['media_id'], $slide['alt']);
                    $slides[] = $slide;
                }
                $content['slides'] = $slides;
                break;

            case BlockType::PromoSplit:
                $content['image'] = self::image($media->get($content['media_id'] ?? 0), $content['alt'] ?? null, $locale);
                unset($content['media_id'], $content['alt']);
                break;

            case BlockType::CategoryTiles:
                unset($content['category_ids'], $content['source'], $content['limit']);
                /** @var Collection<int, Category> $categories */
                $categories = $data['categories'];
                $out['categories'] = $categories->map(static function (Category $category) use ($request): array {
                    $category->setRelation('children', new \Illuminate\Database\Eloquent\Collection);

                    return (new StorefrontCategoryNodeResource($category))->resolve($request);
                })->values()->all();
                break;

            case BlockType::AgeTiles:
                $out['age_counts'] = (object) $data['age_counts'];
                break;

            case BlockType::ProductRail:
                $out['products'] = StorefrontProductCardResource::collection($data['products'])->resolve($request);
                $out['see_all_href'] = $data['see_all_href'] ?? null;
                $content = ['title' => $content['title'] ?? null, 'limit' => $content['limit'] ?? null];
                break;

            case BlockType::BrandStrip:
                $out['brands'] = $data['brands']->map(static fn (Brand $brand): array => [
                    'name' => $locale === 'bn' && $brand->name_bn ? $brand->name_bn : $brand->name,
                    'slug' => $brand->slug,
                    'logo_url' => AdminBrandResource::logoUrl($brand),
                ])->values()->all();
                $content = ['title' => $content['title'] ?? null];
                break;

            case BlockType::Testimonials:
                $out['testimonials'] = $data['testimonials']->map(static fn (Testimonial $t): array => [
                    'id' => $t->id,
                    'name' => $locale === 'bn' && $t->name_bn ? $t->name_bn : $t->name,
                    'location' => $locale === 'bn' && $t->location_bn ? $t->location_bn : $t->location,
                    'quote' => $locale === 'bn' && $t->quote_bn ? $t->quote_bn : $t->quote,
                    'rating' => $t->rating,
                    'image' => self::image($t->media, null, $locale),
                ])->values()->all();
                break;

            case BlockType::BlogTeaser:
                // The blog's own card shape (StorefrontBlogPostCard), both languages, like every blog list.
                $out['posts'] = StorefrontBlogPostCardResource::collection($data['posts'])->resolve($request);
                break;

            default:
                break;
        }

        $out['content'] = (object) $content;

        return $out;
    }

    /** @return array{url: string, srcset_webp: string|null, srcset_avif: string|null, width: int|null, height: int|null, alt: string}|null */
    private static function image(?Media $media, mixed $alt, string $locale): ?array
    {
        $url = $media?->url(self::HERO_WIDTH);
        if ($media === null || $url === null) {
            return null;
        }

        $mediaAlt = $locale === 'bn' && $media->alt_text_bn ? $media->alt_text_bn : $media->alt_text;

        return [
            'url' => $url,
            'srcset_webp' => $media->srcset('webp'),
            'srcset_avif' => $media->srcset('avif'),
            'width' => $media->width,
            'height' => $media->height,
            'alt' => is_string($alt) && $alt !== '' ? $alt : (string) $mediaAlt,
        ];
    }
}
