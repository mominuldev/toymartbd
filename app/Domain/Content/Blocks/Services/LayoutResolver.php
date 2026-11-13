<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Services;

use App\Domain\Catalog\DTOs\ProductListingFilters;
use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductDescription;
use App\Domain\Catalog\Services\ProductListing;
use App\Domain\Content\Blocks\DTOs\ResolvedBlock;
use App\Domain\Content\Blocks\Enums\BlockType;
use App\Domain\Content\Blocks\Enums\ProductRailRule;
use App\Domain\Content\Blog\Models\BlogPost;
use App\Domain\Content\Models\Testimonial;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a block snapshot (live version or draft) into what the storefront renders for one
 * locale at one instant. Server-side guardrails (rules 25b/25c):
 *
 * - schedule window, enabled flag and per-locale visibility are applied here;
 * - product rails list only listable products (active, published, not archived, an active
 *   variant — ProductListing), drop sold-out products when `hide_sold_out`, and a rail left with
 *   fewer than `min_items` hides itself; archived categories and brands are skipped;
 * - any block whose data comes back empty (no testimonials, no posts, no tiles) is dropped,
 *   never rendered empty;
 * - rich text is re-sanitised on the way out.
 *
 * Best sellers read `order_items` × `orders` as a plain table aggregate (units in non-cancelled,
 * non-returned orders over the window) — a read, not a cross-domain write (rule 2).
 */
final class LayoutResolver
{
    public function __construct(private readonly ProductListing $listing) {}

    /**
     * @param  list<array<string, mixed>>  $blocks  snapshots in page order
     * @return list<ResolvedBlock>
     */
    public function resolve(array $blocks, string $locale, CarbonImmutable $at): array
    {
        $out = [];
        $media = $this->mediaFor($blocks);

        foreach ($blocks as $block) {
            $type = BlockType::tryFrom((string) ($block['type'] ?? ''));
            if ($type === null || ! BlockSchedule::isLive($block, $at) || ! (bool) ($block['show_'.$locale] ?? true)) {
                continue;
            }

            /** @var array<string, mixed> $raw */
            $raw = is_array($block['content'] ?? null) ? $block['content'] : [];
            $resolved = $this->resolveOne($type, $raw, self::localize($raw, $locale), $media);

            if ($resolved === null) {
                continue;
            }

            [$content, $data] = $resolved;
            $out[] = new ResolvedBlock(
                id: (string) ($block['uuid'] ?? ''),
                type: $type,
                showMobile: (bool) ($block['show_mobile'] ?? true),
                showDesktop: (bool) ($block['show_desktop'] ?? true),
                content: $content,
                data: $data,
            );
        }

        return $out;
    }

    /**
     * Fold `x_bn` into `x` for Bangla (English when Bangla is empty) and drop every `_bn` key.
     *
     * @param  array<array-key, mixed>  $content
     * @return array<array-key, mixed>
     */
    public static function localize(array $content, string $locale): array
    {
        $out = [];
        foreach ($content as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_bn')) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::localize($value, $locale);

                continue;
            }
            if (is_string($key) && $locale === 'bn') {
                $bn = $content[$key.'_bn'] ?? null;
                $out[$key] = is_string($bn) && $bn !== '' ? $bn : $value;

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<array-key, mixed>  $content
     * @param  \Illuminate\Support\Collection<int, Media>  $media
     * @return array{0: array<array-key, mixed>, 1: array<string, mixed>}|null
     */
    private function resolveOne(BlockType $type, array $raw, array $content, \Illuminate\Support\Collection $media): ?array
    {
        switch ($type) {
            case BlockType::Hero:
                $slides = is_array($content['slides'] ?? null) ? array_values($content['slides']) : [];

                return $slides === [] ? null : [$content, ['media' => $media]];

            case BlockType::PromoSplit:
                return [$content, ['media' => $media]];

            case BlockType::CategoryTiles:
                $categories = $this->categories($raw);

                return $categories->isEmpty() ? null : [$content, ['categories' => $categories]];

            case BlockType::AgeTiles:
                return [$content, ['age_counts' => $this->ageCounts()]];

            case BlockType::ProductRail:
                $products = $this->products($raw);
                $min = max(1, (int) ($raw['min_items'] ?? 1));

                return $products->count() < $min ? null : [$content, ['products' => $products, 'see_all_href' => $this->railHref($raw)]];

            case BlockType::BrandStrip:
                $brands = $this->brands($raw);

                return $brands->isEmpty() ? null : [$content, ['brands' => $brands]];

            case BlockType::Testimonials:
                $rows = Testimonial::query()->with('media')->where('is_published', true)
                    ->orderBy('position')->orderBy('id')->limit(max(1, (int) ($raw['limit'] ?? 6)))->get();

                return $rows->isEmpty() ? null : [$content, ['testimonials' => $rows]];

            case BlockType::BlogTeaser:
                $posts = BlogPost::query()->live()->with(['cover', 'category', 'author'])
                    ->orderByDesc('published_at')->orderByDesc('id')->limit(max(1, (int) ($raw['limit'] ?? 3)))->get();

                return $posts->isEmpty() ? null : [$content, ['posts' => $posts]];

            case BlockType::RichText:
                $content['html'] = ProductDescription::html(is_string($content['html'] ?? null) ? $content['html'] : null);

                return $content['html'] === null ? null : [$content, []];

            case BlockType::Faq:
                $items = is_array($content['items'] ?? null) ? $content['items'] : [];

                return $items === [] ? null : [$content, []];

            case BlockType::UspBand:
                return [$content, []];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return \Illuminate\Support\Collection<int, Media>
     */
    private function mediaFor(array $blocks): \Illuminate\Support\Collection
    {
        $ids = [];
        foreach ($blocks as $block) {
            $content = is_array($block['content'] ?? null) ? $block['content'] : [];
            if (is_numeric($content['media_id'] ?? null)) {
                $ids[] = (int) $content['media_id'];
            }
            foreach (is_array($content['slides'] ?? null) ? $content['slides'] : [] as $slide) {
                if (is_array($slide) && is_numeric($slide['media_id'] ?? null)) {
                    $ids[] = (int) $slide['media_id'];
                }
            }
        }

        return $ids === [] ? collect() : Media::query()->whereIn('id', array_unique($ids))->get()->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return Collection<int, Category>
     */
    private function categories(array $raw): Collection
    {
        if (($raw['source'] ?? 'top_level') === 'manual') {
            $ids = array_map(intval(...), is_array($raw['category_ids'] ?? null) ? $raw['category_ids'] : []);
            $found = Category::query()->active()->whereIn('id', $ids)->get()->keyBy('id');

            return new Collection(array_values(array_filter(array_map(static fn (int $id): ?Category => $found->get($id), $ids))));
        }

        return Category::query()->active()->where('show_in_menu', true)->whereNull('parent_id')
            ->inTreeOrder()->limit(max(1, (int) ($raw['limit'] ?? 8)))->get();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return Collection<int, Brand>
     */
    private function brands(array $raw): Collection
    {
        $ids = array_map(intval(...), is_array($raw['brand_ids'] ?? null) ? $raw['brand_ids'] : []);
        $limit = max(1, (int) ($raw['limit'] ?? 12));

        if ($ids !== []) {
            $found = Brand::query()->where('status', BrandStatus::Active->value)->whereIn('id', $ids)->get()->keyBy('id');

            return new Collection(array_slice(array_values(array_filter(array_map(static fn (int $id): ?Brand => $found->get($id), $ids))), 0, $limit));
        }

        return Brand::query()->where('status', BrandStatus::Active->value)->whereNotNull('logo_path')->orderBy('name')->limit($limit)->get();
    }

    /** @return array<string, int> age band → listable product count */
    private function ageCounts(): array
    {
        $counts = [];
        foreach ($this->listing->facets(new ProductListingFilters)['age_band'] as $option) {
            $counts[$option['value']] = $option['count'];
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return Collection<int, Product>
     */
    private function products(array $raw): Collection
    {
        $rule = ProductRailRule::tryFrom((string) ($raw['rule'] ?? '')) ?? ProductRailRule::BestSellers;
        $limit = max(1, (int) ($raw['limit'] ?? 8));
        $inStock = (bool) ($raw['hide_sold_out'] ?? true) ? true : null;

        $filters = new ProductListingFilters(
            category: $rule === ProductRailRule::Category ? $this->categorySlug($raw['category_id'] ?? null) : null,
            brands: $rule === ProductRailRule::Brand ? array_filter([$this->brandSlug($raw['brand_id'] ?? null)]) : [],
            inStock: $inStock,
        );

        if ($rule === ProductRailRule::Category && $filters->category === null) {
            return new Collection;
        }
        if ($rule === ProductRailRule::Brand && $filters->brands === []) {
            return new Collection;
        }

        $query = $this->listing->filtered($filters)->with(ProductListing::RELATIONS);

        switch ($rule) {
            case ProductRailRule::Manual:
                $ids = array_map(intval(...), is_array($raw['product_ids'] ?? null) ? $raw['product_ids'] : []);
                if ($ids === []) {
                    return new Collection;
                }
                $found = $query->whereIn('products.id', $ids)->get(['products.*'])->keyBy('id');

                return new Collection(array_slice(array_values(array_filter(array_map(static fn (int $id): ?Product => $found->get($id), $ids))), 0, $limit));

            case ProductRailRule::New:
                $query->orderByDesc('products.published_at')->orderByDesc('products.id');
                break;

            case ProductRailRule::BestSellers:
                $since = CarbonImmutable::now('UTC')->subDays(max(1, (int) ($raw['window_days'] ?? 30)));
                $sales = DB::table('order_items')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereNotNull('order_items.product_id')
                    ->whereNotIn('orders.status', ['cancelled', 'returned'])
                    ->where('orders.created_at', '>=', $since)
                    ->groupBy('order_items.product_id')
                    ->selectRaw('order_items.product_id as sold_product_id, SUM(order_items.quantity - order_items.quantity_returned) as units_sold');
                $query->leftJoinSub($sales, 'sales', 'sales.sold_product_id', '=', 'products.id')
                    ->orderByRaw('COALESCE(sales.units_sold, 0) DESC')
                    ->orderByDesc('products.is_featured')->orderByDesc('products.rating_count')->orderByDesc('products.id');
                break;

            default:
                $query->orderByDesc('products.is_featured')->orderByDesc('products.published_at')->orderByDesc('products.id');
        }

        /** @var Builder<Product> $query */
        return $query->limit($limit)->get(['products.*']);
    }

    /**
     * Where the rail's "See all" goes: the listing the rule mirrors; none for a manual pick.
     *
     * @param  array<string, mixed>  $raw
     */
    private function railHref(array $raw): ?string
    {
        if (! (bool) ($raw['show_see_all'] ?? true)) {
            return null;
        }

        return match (ProductRailRule::tryFrom((string) ($raw['rule'] ?? ''))) {
            ProductRailRule::New => '/toys?sort=newest',
            ProductRailRule::BestSellers => '/toys',
            ProductRailRule::Category => ($slug = $this->categorySlug($raw['category_id'] ?? null)) === null ? null : '/categories/'.$slug,
            ProductRailRule::Brand => ($slug = $this->brandSlug($raw['brand_id'] ?? null)) === null ? null : '/brands/'.$slug,
            default => null,
        };
    }

    private function categorySlug(mixed $id): ?string
    {
        if (! is_numeric($id)) {
            return null;
        }

        $slug = Category::query()->active()->whereKey((int) $id)->value('slug');

        return is_string($slug) ? $slug : null;
    }

    private function brandSlug(mixed $id): ?string
    {
        if (! is_numeric($id)) {
            return null;
        }

        $slug = Brand::query()->where('status', BrandStatus::Active->value)->whereKey((int) $id)->value('slug');

        return is_string($slug) ? $slug : null;
    }
}
