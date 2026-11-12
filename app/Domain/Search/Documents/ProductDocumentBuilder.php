<?php

declare(strict_types=1);

namespace App\Domain\Search\Documents;

use App\Domain\Catalog\Contracts\ProductSearchDocuments;
use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\PriceBand;
use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Services\StockAvailability;
use App\Domain\Search\Support\SearchText;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The product search document (P4-CAT-14; field reference in docs/api/search.md).
 *
 * Facet fields mirror the storefront listing's filter semantics exactly
 * (App\Domain\Catalog\Services\ProductListing), so a search facet and a category-page
 * facet count the same way:
 *
 * - `category_slugs`: every ACTIVE category the product is assigned to, plus each one's
 *   active ancestors — `filter[category]=x` includes x's whole subtree.
 * - `age_min` / `age_max`: open bounds stored as 0 / AGE_OPEN_MAX so the range filter
 *   needs no NULL handling; `age_bands`: every band the inclusive range overlaps.
 * - `gender`: null is stored as `unisex` ("For everyone" also matches no gender).
 * - `price_min`: the lowest active variant price, the listing price. A float here is a
 *   filter/sort key only — prices shown to shoppers are always re-read from the database
 *   (cards are hydrated from MySQL), never from the index (rule 6).
 * - `rating`: rating_avg with null as 0; `rating_floor` buckets it for the "N★ & up" facet.
 * - `in_stock`: App\Domain\Inventory\Services\StockAvailability (re-indexed on
 *   StockLevelChanged); `stock_rank` 1/0 ranks in-stock products first.
 * - `popularity`: a provisional sort key — `rating_count × 10 + view_count` — until order
 *   history exists to rank by units sold.
 * - `updated_ts`: the product row's updated_at, read back by the index-lag check.
 */
final class ProductDocumentBuilder implements ProductSearchDocuments
{
    /** 18 years + 1 month: "no upper bound" for the age range filter. */
    public const int AGE_OPEN_MAX = 217;

    /** @var array<int, true> product id → in stock */
    private array $inStock = [];

    /** @var array<int, Category>|null every live category by id, loaded once per batch */
    private ?array $categories = null;

    public function __construct(private readonly StockAvailability $stock) {}

    /** @param  Collection<int, Product>  $products */
    public function prepare(Collection $products): void
    {
        if ($products->isEmpty()) {
            return;
        }

        (new EloquentCollection($products->all()))->loadMissing(['brand', 'activeVariants', 'categories']);

        $this->inStock = array_fill_keys($this->stock->inStockProductIds($products->map(static fn (Product $p): int => $p->id)->all()), true);

        $this->categories = Category::query()
            ->get(['id', 'parent_id', 'name', 'name_bn', 'slug', 'status'])
            ->keyBy('id')
            ->all();
    }

    public function isSearchable(Product $product): bool
    {
        return $product->isActive()
            && ! $product->trashed()
            && $product->published_at !== null
            && $product->published_at->lessThanOrEqualTo(now())
            && $product->activeVariants->isNotEmpty();
    }

    /** @return array<string, mixed> */
    public function build(Product $product): array
    {
        if ($this->categories === null) {
            $this->prepare(collect([$product]));
        }

        $variants = $product->activeVariants->filter(static fn (ProductVariant $v): bool => $v->status === VariantStatus::Active);
        $prices = $variants->map(static fn (ProductVariant $v): Money => $v->price);
        $min = $prices->reduce(static fn (?Money $carry, Money $p): Money => $carry === null || $p->lessThan($carry) ? $p : $carry);
        $max = $prices->reduce(static fn (?Money $carry, Money $p): Money => $carry === null || $p->greaterThan($carry) ? $p : $carry);

        [$categoryNames, $categoryNamesBn, $categorySlugs] = $this->categoryTrail($product);

        $brand = $product->brand !== null && $product->brand->isActive() ? $product->brand : null;
        $rating = $product->rating_avg === null ? 0.0 : (float) $product->rating_avg;
        $inStock = isset($this->inStock[$product->id]);

        return [
            'id' => $product->id,
            'name' => SearchText::normalize($product->name),
            'name_bn' => SearchText::normalize($product->name_bn),
            'brand' => $brand?->name,
            'brand_bn' => $brand === null ? null : SearchText::normalize($brand->name_bn),
            'brand_slug' => $brand?->slug,
            'categories' => $categoryNames,
            'categories_bn' => $categoryNamesBn,
            'category_slugs' => $categorySlugs,
            'short_description' => SearchText::normalize(strip_tags((string) $product->short_description)),
            'short_description_bn' => SearchText::normalize(strip_tags((string) $product->short_description_bn)),
            'skus' => $variants->pluck('sku')->values()->all(),
            'age_min' => $product->age_min_months ?? 0,
            'age_max' => $product->age_max_months ?? self::AGE_OPEN_MAX,
            'age_bands' => self::ageBands($product->age_min_months, $product->age_max_months),
            'gender' => ($product->gender ?? Gender::Unisex)->value,
            'price_min' => $min === null ? 0.0 : (float) $min->toDecimal(),
            'price_max' => $max === null ? 0.0 : (float) $max->toDecimal(),
            'price_band' => $min === null ? null : self::priceBand($min->toDecimal())?->value,
            'rating' => $rating,
            'rating_floor' => (int) floor($rating),
            'rating_count' => $product->rating_count,
            'is_educational' => $product->is_educational,
            'is_featured' => $product->is_featured,
            'in_stock' => $inStock,
            'stock_rank' => $inStock ? 1 : 0,
            'popularity' => $product->rating_count * 10 + $product->view_count,
            'published_at' => $product->published_at?->getTimestamp() ?? 0,
            'updated_ts' => $product->updated_at?->getTimestamp() ?? 0,
        ];
    }

    /**
     * Same overlap rule as ProductListing::ageBandSql(): the product's inclusive range
     * [min, max] (null = open) shares at least one month with the band [bandMin, bandMax).
     *
     * @return list<string>
     */
    public static function ageBands(?int $min, ?int $max): array
    {
        $bands = [];
        foreach (AgeBand::cases() as $band) {
            $belowTop = $band->max() === null || $min === null || $min < $band->max();
            $aboveBottom = $max === null || $max >= $band->min();
            if ($belowTop && $aboveBottom) {
                $bands[] = $band->value;
            }
        }

        return $bands;
    }

    /** The one band containing `$price` (bands partition every price), as ProductListing::priceBandSql(). */
    public static function priceBand(string $price): ?PriceBand
    {
        foreach (PriceBand::cases() as $band) {
            if (bccomp($price, $band->min(), 2) >= 0 && ($band->max() === null || bccomp($price, $band->max(), 2) < 0)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * Names (en, bn) and slugs of the product's active categories and their active
     * ancestors, de-duplicated, nearest first.
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function categoryTrail(Product $product): array
    {
        $names = [];
        $namesBn = [];
        $slugs = [];

        foreach ($product->categories as $assigned) {
            $category = $this->categories[$assigned->id] ?? null;
            if ($category === null || $category->status !== CategoryStatus::Active) {
                continue;
            }
            $guard = 0;
            // Walk up through inactive ancestors too (the listing matches by subtree), but
            // only index the active ones: an archived category is never a filter value.
            while ($category !== null && $guard++ < 32) {
                if ($category->status === CategoryStatus::Active && ! isset($slugs[$category->slug])) {
                    $slugs[$category->slug] = true;
                    $names[] = SearchText::normalize($category->name);
                    if ($category->name_bn !== null && $category->name_bn !== '') {
                        $namesBn[] = SearchText::normalize($category->name_bn);
                    }
                }
                $category = $category->parent_id === null ? null : ($this->categories[$category->parent_id] ?? null);
            }
        }

        return [array_values(array_unique($names)), array_values(array_unique($namesBn)), array_keys($slugs)];
    }
}
