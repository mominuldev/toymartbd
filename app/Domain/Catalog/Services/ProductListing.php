<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Contracts\AvailableStockQuery;
use App\Domain\Catalog\DTOs\ProductListingFilters as F;
use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\PriceBand;
use App\Domain\Catalog\Enums\ProductSort;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Closure;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The storefront product listing (P4-CAT-13): which products a category or brand
 * page shows, in what order, and the facet counts beside them. SQL for now;
 * Meilisearch takes over search and facets in P4-CAT-14.
 *
 * A listed product is active, published (`published_at` reached), not deleted, and
 * has at least one active variant. Its listing price is its lowest active variant
 * price. Everything runs against one derived table aliased `products`, which adds
 * `listing_price_min`, `listing_rating` (rating with nulls as 0) and
 * `listing_in_stock` (1 when an active variant has available stock > 0, from
 * {@see AvailableStockQuery}) so they can be filtered, sorted and cursor-paginated
 * like plain columns.
 *
 * Facets are disjunctive: each facet's counts apply every filter except its own,
 * so selecting LEGO still shows how many Hape products the other filters allow.
 */
final class ProductListing
{
    /** @var array<string, Category|null> */
    private array $categories = [];

    /** The eager loads a listing card needs. */
    public const array RELATIONS = ['brand', 'activeVariants', 'images'];

    public function __construct(private readonly AvailableStockQuery $stock) {}

    /**
     * One page of products, eager-loaded for the card.
     *
     * @return CursorPaginator<int, Product>
     */
    public function paginate(F $filters, ProductSort $sort, int $perPage, ?string $cursor): CursorPaginator
    {
        $query = $this->filtered($filters)->with(self::RELATIONS);

        foreach ($sort->orders() as [$column, $direction]) {
            $query->orderBy('products.'.$column, $direction);
        }

        return $query->cursorPaginate($perPage, ['products.*'], 'cursor', $cursor);
    }

    public function count(F $filters): int
    {
        return $this->filtered($filters)->toBase()->count();
    }

    /** The active category behind `filter[category]`, or null when unset or unknown. */
    public function category(?string $slug): ?Category
    {
        if ($slug === null) {
            return null;
        }

        if (! array_key_exists($slug, $this->categories)) {
            $this->categories[$slug] = Category::query()->active()->where('slug', $slug)->first();
        }

        return $this->categories[$slug];
    }

    /**
     * Facet counts for the result set, each with its own filter excluded.
     *
     * @return array{
     *     category: list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>,
     *     brand: list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>,
     *     age_band: list<array{value: string, min_months: int, max_months: int|null, count: int, selected: bool}>,
     *     price_band: list<array{value: string, min: string, max: string|null, count: int, selected: bool}>,
     *     gender: list<array{value: string, count: int, selected: bool}>,
     *     rating: list<array{value: int, count: int, selected: bool}>,
     *     educational: array{count: int, selected: bool},
     *     availability: array{count: int, selected: bool},
     * }
     */
    public function facets(F $filters): array
    {
        return [
            'category' => $this->categoryFacet($filters),
            'brand' => $this->brandFacet($filters),
            'age_band' => $this->ageFacet($filters),
            'price_band' => $this->priceFacet($filters),
            'gender' => $this->genderFacet($filters),
            'rating' => $this->ratingFacet($filters),
            'educational' => $this->educationalFacet($filters),
            'availability' => $this->availabilityFacet($filters),
        ];
    }

    /**
     * The listable products with every filter applied except `$except` (a facet key).
     *
     * @return Builder<Product>
     */
    public function filtered(F $filters, ?string $except = null): Builder
    {
        $query = $this->base();

        if ($except !== F::CATEGORY && $filters->category !== null) {
            $category = $this->category($filters->category);
            $category === null
                ? $query->whereRaw('1 = 0')
                : $query->whereExists($this->inSubtree($category));
        }

        if ($except !== F::BRAND && $filters->brands !== []) {
            $query->whereIn('products.brand_id', Brand::query()->whereIn('slug', $filters->brands)->select('id'));
        }

        if ($except !== F::AGE) {
            if ($filters->ageMonths !== null) {
                $age = $filters->ageMonths;
                $query->where(fn (Builder $q) => $q->whereNull('products.age_min_months')->orWhere('products.age_min_months', '<=', $age))
                    ->where(fn (Builder $q) => $q->whereNull('products.age_max_months')->orWhere('products.age_max_months', '>=', $age));
            }

            if ($filters->ageBands !== []) {
                $query->where(function (Builder $q) use ($filters): void {
                    foreach ($filters->ageBands as $band) {
                        $q->orWhereRaw(self::ageBandSql($band));
                    }
                });
            }
        }

        if ($except !== F::PRICE) {
            if ($filters->priceMin !== null) {
                $query->whereRaw('products.listing_price_min >= CAST(? AS DECIMAL(14,2))', [$filters->priceMin]);
            }

            if ($filters->priceMax !== null) {
                $query->whereRaw('products.listing_price_min <= CAST(? AS DECIMAL(14,2))', [$filters->priceMax]);
            }

            if ($filters->priceBands !== []) {
                $query->where(function (Builder $q) use ($filters): void {
                    foreach ($filters->priceBands as $band) {
                        [$sql, $bindings] = self::priceBandSql($band);
                        $q->orWhereRaw($sql, $bindings);
                    }
                });
            }
        }

        if ($except !== F::GENDER && $filters->genders !== []) {
            $values = array_map(static fn (Gender $g): string => $g->value, $filters->genders);
            $query->where(function (Builder $q) use ($values): void {
                $q->whereIn('products.gender', $values);
                if (in_array(Gender::Unisex->value, $values, true)) {
                    $q->orWhereNull('products.gender');
                }
            });
        }

        if ($except !== F::RATING && $filters->minRating !== null) {
            $query->where('products.listing_rating', '>=', $filters->minRating);
        }

        if ($except !== F::EDUCATIONAL && $filters->educational !== null) {
            $query->where('products.is_educational', $filters->educational);
        }

        if ($except !== F::AVAILABILITY && $filters->inStock !== null) {
            $query->where('products.listing_in_stock', $filters->inStock ? 1 : 0);
        }

        return $query;
    }

    /**
     * Listable products with their listing columns, as a derived table named
     * `products` so the model, its soft-delete scope and the cursor all work.
     *
     * @return Builder<Product>
     */
    private function base(): Builder
    {
        $prices = DB::table('product_variants')
            ->leftJoinSub($this->stock->availableByVariant(), 'variant_stock', 'variant_stock.variant_id', '=', 'product_variants.id')
            ->select('product_variants.product_id')
            ->selectRaw('MIN(product_variants.price) as listing_price_min')
            ->selectRaw('MAX(CASE WHEN COALESCE(variant_stock.available, 0) > 0 THEN 1 ELSE 0 END) as listing_in_stock')
            ->where('product_variants.status', VariantStatus::Active->value)
            ->groupBy('product_variants.product_id');

        $inner = Product::query()
            ->published()
            ->joinSub($prices, 'listing_prices', 'listing_prices.product_id', '=', 'products.id')
            ->select('products.*', 'listing_prices.listing_price_min', 'listing_prices.listing_in_stock')
            ->selectRaw('COALESCE(products.rating_avg, 0) as listing_rating');

        return Product::query()->fromSub($inner, 'products');
    }

    /** `EXISTS` subquery: the product sits in `$category` or any active descendant. */
    private function inSubtree(Category $category): Closure
    {
        return static function (QueryBuilder $q) use ($category): void {
            $q->selectRaw('1')
                ->from('category_product')
                ->join('categories', 'categories.id', '=', 'category_product.category_id')
                ->whereColumn('category_product.product_id', 'products.id')
                ->where('categories._lft', '>=', $category->_lft)
                ->where('categories._rgt', '<=', $category->_rgt)
                ->where('categories.status', CategoryStatus::Active->value)
                ->whereNull('categories.deleted_at');
        };
    }

    private static function ageBandSql(AgeBand $band): string
    {
        $upper = $band->max() === null ? '1 = 1' : '(products.age_min_months IS NULL OR products.age_min_months < '.$band->max().')';

        return '('.$upper.' AND (products.age_max_months IS NULL OR products.age_max_months >= '.$band->min().'))';
    }

    /** @return array{0: string, 1: list<string>} */
    private static function priceBandSql(PriceBand $band): array
    {
        if ($band->max() === null) {
            return ['(products.listing_price_min >= CAST(? AS DECIMAL(14,2)))', [$band->min()]];
        }

        return [
            '(products.listing_price_min >= CAST(? AS DECIMAL(14,2)) AND products.listing_price_min < CAST(? AS DECIMAL(14,2)))',
            [$band->min(), $band->max()],
        ];
    }

    /**
     * `SUM(CASE WHEN … THEN 1 ELSE 0 END)` per condition, in one query.
     *
     * @param  Builder<Product>  $query
     * @param  array<string, array{0: string, 1: list<string|int>}>  $conditions  alias => [sql, bindings]
     * @return array<string, int>
     */
    private function sums(Builder $query, array $conditions): array
    {
        $base = $query->toBase();
        $base->columns = null;

        foreach ($conditions as $alias => [$sql, $bindings]) {
            $base->selectRaw("SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as {$alias}", $bindings);
        }

        $row = (array) ($base->first() ?? []);
        $counts = [];
        foreach (array_keys($conditions) as $alias) {
            $counts[$alias] = (int) ($row[$alias] ?? 0);
        }

        return $counts;
    }

    /** @return list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}> */
    private function categoryFacet(F $filters): array
    {
        $current = $this->category($filters->category);
        if ($filters->category !== null && $current === null) {
            return [];
        }

        $children = Category::query()
            ->active()
            ->where('parent_id', $current?->id)
            ->inTreeOrder()
            ->get(['id', 'parent_id', '_lft', '_rgt', 'name', 'name_bn', 'slug']);

        if ($children->isEmpty()) {
            return [];
        }

        $conditions = [];
        foreach ($children->values() as $i => $child) {
            $conditions['c'.$i] = [
                'EXISTS (SELECT 1 FROM category_product INNER JOIN categories ON categories.id = category_product.category_id'
                .' WHERE category_product.product_id = products.id AND categories._lft >= ? AND categories._rgt <= ?'
                .' AND categories.status = ? AND categories.deleted_at IS NULL)',
                [$child->_lft, $child->_rgt, CategoryStatus::Active->value],
            ];
        }

        $counts = $this->sums($this->filtered($filters, F::CATEGORY), $conditions);

        $facet = [];
        foreach ($children->values() as $i => $child) {
            $facet[] = [
                'value' => $child->slug,
                'label' => $child->name,
                'label_bn' => $child->name_bn,
                'count' => $counts['c'.$i],
                'selected' => false,
            ];
        }

        return $facet;
    }

    /**
     * Brands with at least one product under the other filters, by name, plus any
     * selected brand (count 0) so its chip and checkbox never vanish.
     *
     * @return list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>
     */
    private function brandFacet(F $filters): array
    {
        $rows = $this->filtered($filters, F::BRAND)->toBase()
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->where('brands.status', BrandStatus::Active->value)
            ->groupBy('brands.id', 'brands.slug', 'brands.name', 'brands.name_bn')
            ->orderBy('brands.name')
            ->orderBy('brands.id')
            ->select('brands.slug', 'brands.name', 'brands.name_bn')
            ->selectRaw('COUNT(*) as aggregate_count')
            ->get();

        $facet = [];
        $seen = [];
        foreach ($rows as $row) {
            $slug = (string) $row->slug;
            $seen[$slug] = true;
            $facet[] = [
                'value' => $slug,
                'label' => (string) $row->name,
                'label_bn' => $row->name_bn === null ? null : (string) $row->name_bn,
                'count' => (int) $row->aggregate_count,
                'selected' => in_array($slug, $filters->brands, true),
            ];
        }

        $missing = array_values(array_filter($filters->brands, static fn (string $s): bool => ! isset($seen[$s])));
        if ($missing !== []) {
            foreach (Brand::query()->active()->whereIn('slug', $missing)->orderBy('name')->get(['slug', 'name', 'name_bn']) as $brand) {
                $facet[] = ['value' => $brand->slug, 'label' => $brand->name, 'label_bn' => $brand->name_bn, 'count' => 0, 'selected' => true];
            }
        }

        return $facet;
    }

    /** @return list<array{value: string, min_months: int, max_months: int|null, count: int, selected: bool}> */
    private function ageFacet(F $filters): array
    {
        $conditions = [];
        foreach (AgeBand::cases() as $i => $band) {
            $conditions['a'.$i] = [self::ageBandSql($band), []];
        }
        $counts = $this->sums($this->filtered($filters, F::AGE), $conditions);

        $facet = [];
        foreach (AgeBand::cases() as $i => $band) {
            $facet[] = [
                'value' => $band->value,
                'min_months' => $band->min(),
                'max_months' => $band->max(),
                'count' => $counts['a'.$i],
                'selected' => in_array($band, $filters->ageBands, true),
            ];
        }

        return $facet;
    }

    /** @return list<array{value: string, min: string, max: string|null, count: int, selected: bool}> */
    private function priceFacet(F $filters): array
    {
        $conditions = [];
        foreach (PriceBand::cases() as $i => $band) {
            $conditions['p'.$i] = self::priceBandSql($band);
        }
        $counts = $this->sums($this->filtered($filters, F::PRICE), $conditions);

        $facet = [];
        foreach (PriceBand::cases() as $i => $band) {
            $facet[] = [
                'value' => $band->value,
                'min' => $band->min(),
                'max' => $band->max(),
                'count' => $counts['p'.$i],
                'selected' => in_array($band, $filters->priceBands, true),
            ];
        }

        return $facet;
    }

    /**
     * "For everyone" first (A7.2): `unisex` counts products with no gender too.
     *
     * @return list<array{value: string, count: int, selected: bool}>
     */
    private function genderFacet(F $filters): array
    {
        $counts = $this->sums($this->filtered($filters, F::GENDER), [
            'g_unisex' => ["(products.gender = 'unisex' OR products.gender IS NULL)", []],
            'g_boys' => ["products.gender = 'boys'", []],
            'g_girls' => ["products.gender = 'girls'", []],
        ]);

        $facet = [];
        foreach ([Gender::Unisex, Gender::Boys, Gender::Girls] as $gender) {
            $facet[] = [
                'value' => $gender->value,
                'count' => $counts['g_'.$gender->value],
                'selected' => in_array($gender, $filters->genders, true),
            ];
        }

        return $facet;
    }

    /** @return list<array{value: int, count: int, selected: bool}> */
    private function ratingFacet(F $filters): array
    {
        $conditions = [];
        foreach ([4, 3, 2, 1] as $stars) {
            $conditions['r'.$stars] = ['products.listing_rating >= '.$stars, []];
        }
        $counts = $this->sums($this->filtered($filters, F::RATING), $conditions);

        $facet = [];
        foreach ([4, 3, 2, 1] as $stars) {
            $facet[] = ['value' => $stars, 'count' => $counts['r'.$stars], 'selected' => $filters->minRating === $stars];
        }

        return $facet;
    }

    /** @return array{count: int, selected: bool} */
    private function educationalFacet(F $filters): array
    {
        $counts = $this->sums($this->filtered($filters, F::EDUCATIONAL), ['e' => ['products.is_educational = 1', []]]);

        return ['count' => $counts['e'], 'selected' => $filters->educational === true];
    }

    /**
     * In-stock products under the other filters (the "In stock only" toggle).
     *
     * @return array{count: int, selected: bool}
     */
    private function availabilityFacet(F $filters): array
    {
        $counts = $this->sums($this->filtered($filters, F::AVAILABILITY), ['s' => ['products.listing_in_stock = 1', []]]);

        return ['count' => $counts['s'], 'selected' => $filters->inStock === true];
    }
}
