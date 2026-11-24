<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Content\Blocks\DTOs\ResolvedBlock;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Schemas\BlockSchema;
use App\Domain\Content\Blocks\Services\VideoLengths;
use App\Domain\Content\Blocks\ValueObjects\VideoEmbed;
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
 * hero `content.slides[].image`, `content.promos[].image` and `content.proof.avatars`, promo_split, why_band and image `image`, story_hero `images` (0–3), category_tiles `categories` (the
 * StorefrontCategoryNode shape), age_tiles `age_counts` + `content.cards[].image`, product_rail `products`
 * (StorefrontProductCard) + `see_all_href` + `chips` ({id, name, slug, products}), promo_banners `content.items[].image`,
 * video_reel `content.items[].poster` + `.embed` ({kind: youtube|facebook|instagram|file, src} or null) + `.product` ({name, slug} or null), brand_strip `brands`, testimonials `testimonials`,
 * blog_teaser `posts` (StorefrontBlogPostCard) + `see_all_href`. Blocks that would render empty are already gone. `version` is null and
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

        // Carousel behaviour, always complete: a block saved before these settings existed answers
        // with the defaults, so the storefront never guesses (BlockSchema::sliderRules()).
        if (in_array($block->type, [BlockType::Hero, BlockType::ProductRail, BlockType::FlashSale, BlockType::VideoReel], true)) {
            $content['slider'] = [...BlockSchema::sliderDefaults(), ...(is_array($content['slider'] ?? null) ? $content['slider'] : [])];
        }

        switch ($block->type) {
            case BlockType::Hero:
                $slides = [];
                foreach (is_array($content['slides'] ?? null) ? $content['slides'] : [] as $slide) {
                    $slide['image'] = self::image($media->get($slide['media_id'] ?? 0), $slide['alt'] ?? null, $locale);
                    unset($slide['media_id'], $slide['alt']);
                    $slides[] = $slide;
                }
                $content['slides'] = $slides;
                $promos = [];
                foreach (is_array($content['promos'] ?? null) ? $content['promos'] : [] as $promo) {
                    $promo['image'] = self::image($media->get($promo['media_id'] ?? 0), $promo['alt'] ?? null, $locale);
                    unset($promo['media_id'], $promo['alt']);
                    $promos[] = $promo;
                }
                $content['promos'] = $promos;
                $proof = is_array($content['proof'] ?? null) ? $content['proof'] : null;
                if ($proof !== null) {
                    $avatars = [];
                    foreach (is_array($proof['media_ids'] ?? null) ? $proof['media_ids'] : [] as $id) {
                        $avatar = self::image($media->get((int) $id), null, $locale);
                        if ($avatar !== null) {
                            $avatars[] = $avatar;
                        }
                    }
                    $proof['avatars'] = $avatars;
                    unset($proof['media_ids']);
                }
                $content['proof'] = $proof;
                break;

            case BlockType::Image:
            case BlockType::WhyBand:
            case BlockType::PromoSplit:
                $content['image'] = self::image($media->get($content['media_id'] ?? 0), $content['alt'] ?? null, $locale);
                unset($content['media_id'], $content['alt']);
                break;

            case BlockType::StoryHero:
                $images = [];
                foreach (is_array($content['media_ids'] ?? null) ? $content['media_ids'] : [] as $id) {
                    $image = self::image($media->get((int) $id), null, $locale);
                    if ($image !== null) {
                        $images[] = $image;
                    }
                }
                $content['images'] = $images;
                unset($content['media_ids']);
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
                $content['cards'] = array_map(static function (array $card) use ($media, $locale): array {
                    $card['image'] = self::image($media->get($card['media_id'] ?? 0), $card['alt'] ?? null, $locale);
                    unset($card['media_id'], $card['alt']);

                    return $card;
                }, array_values(array_filter(is_array($content['cards'] ?? null) ? $content['cards'] : [], is_array(...))));
                break;

            case BlockType::PromoBanners:
                $content['items'] = array_map(static function (array $item) use ($media, $locale): array {
                    $item['image'] = self::image($media->get($item['media_id'] ?? 0), $item['alt'] ?? null, $locale);
                    unset($item['media_id'], $item['alt']);

                    return $item;
                }, array_values(array_filter(is_array($content['items'] ?? null) ? $content['items'] : [], is_array(...))));
                break;

            case BlockType::VideoReel:
                /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Catalog\Models\Product> $reelProducts */
                $reelProducts = $data['products'];
                /** @var array<string, int> $lengths detected seconds by video link */
                $lengths = $data['lengths'] ?? [];
                $content['items'] = array_map(static function (array $item) use ($media, $locale, $reelProducts, $lengths): array {
                    // The editor's typed length wins; else the one read from YouTube or the file (VideoLengths).
                    $detected = $lengths[$item['video_url'] ?? ''] ?? null;
                    $item['duration'] = is_string($item['duration'] ?? null) && $item['duration'] !== '' ? $item['duration'] : ($detected === null ? null : VideoLengths::format($detected));
                    $item['poster'] = self::image($media->get($item['media_id'] ?? 0), $item['alt'] ?? null, $locale);
                    $item['embed'] = VideoEmbed::fromUrl(is_string($item['video_url'] ?? null) ? $item['video_url'] : null)?->toArray();
                    $product = $reelProducts->get((int) ($item['product_id'] ?? 0));
                    $item['product'] = $product === null ? null : [
                        'name' => $locale === 'bn' && $product->name_bn ? $product->name_bn : $product->name,
                        'slug' => $product->slug,
                    ];
                    unset($item['media_id'], $item['alt'], $item['product_id']);

                    return $item;
                }, $content['items']);
                break;

            case BlockType::ProductRail:
                StorefrontProductCardResource::primeStock($data['products']); // one stock query per rail (Phase 9 P-10)
                $out['products'] = StorefrontProductCardResource::collection($data['products'])->resolve($request);
                $out['see_all_href'] = $data['see_all_href'] ?? null;
                $out['chips'] = array_map(static function (array $chip) use ($request, $locale): array {
                    StorefrontProductCardResource::primeStock($chip['products']);

                    return [
                        'id' => $chip['category']->id,
                        'name' => $locale === 'bn' && $chip['category']->name_bn ? $chip['category']->name_bn : $chip['category']->name,
                        'slug' => $chip['category']->slug,
                        'products' => StorefrontProductCardResource::collection($chip['products'])->resolve($request),
                    ];
                }, $data['chips'] ?? []);
                $content = array_intersect_key($content, array_flip(['eyebrow', 'eyebrow_tone', 'title', 'sub', 'layout', 'band', 'see_all_label', 'all_label', 'limit', 'slider']));
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
                $out['see_all_href'] = $data['see_all_href'] ?? null;
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
