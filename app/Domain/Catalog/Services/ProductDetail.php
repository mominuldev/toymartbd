<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The storefront product page (P4-STO-05): one live product with everything the PDP
 * renders, and the candidates for its "Similar toys" row (P4-STO-07).
 *
 * A live product is active, published (`published_at` reached), not deleted, and has
 * at least one active variant — the same rule as a listing card (ProductListing), so a
 * product that is on a listing always has a page and vice versa.
 *
 * Stock is not read here: the Catalog domain does not depend on Inventory (deptrac).
 * The HTTP layer joins StockAvailability onto the variant ids and filters the related
 * candidates to in-stock products (rule 25c).
 */
final class ProductDetail
{
    /** The eager loads the page needs; every one is a single query. */
    public const array RELATIONS = [
        'brand',
        'primaryCategory',
        'activeVariants.attributeValues.attribute',
        'images',
    ];

    /** The live product behind `$slug`, fully loaded, or null. */
    public function find(string $slug): ?Product
    {
        $product = self::live()->where('slug', $slug)->with(self::RELATIONS)->first();

        if ($product === null) {
            return null;
        }

        // Breadcrumbs: the primary category's active ancestors, root first. A deleted
        // (soft) primary category loads as null; an archived one is left out by the resource.
        /** @var Category|null $category */
        $category = $product->primaryCategory;
        $category?->setRelation(
            'ancestors',
            $category->isActive() ? Category::query()->active()->treeAncestorsOf($category)->inTreeOrder()->get() : new Collection,
        );

        return $product;
    }

    /**
     * Related-product candidates, best first and without duplicates: hand-curated and
     * rule-generated `related` pairs in their position order, then other live products
     * from the same primary category (featured first, newest first). Never the product
     * itself; then products sharing any other category. Loaded for
     * StorefrontProductCardResource (ProductListing::RELATIONS) in
     * a fixed number of queries.
     *
     * Returns up to `$limit` candidates; the caller drops out-of-stock ones and keeps
     * the first `$keep`.
     *
     * @return Collection<int, Product>
     */
    public function relatedCandidates(Product $product, int $limit): Collection
    {
        /** @var list<int> $curatedIds */
        $curatedIds = ProductRelation::query()
            ->where('product_id', $product->id)
            ->where('type', RelationType::Related->value)
            ->where('related_product_id', '!=', $product->id)
            ->orderBy('position')
            ->orderBy('id')
            ->pluck('related_product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $curated = $curatedIds === []
            ? new Collection
            : self::live()->whereIn('products.id', $curatedIds)->with(ProductListing::RELATIONS)->get()
                ->sortBy(static fn (Product $p): int => (int) array_search($p->id, $curatedIds, true))
                ->values();

        $picked = $curated->all();

        // Then the same primary category; then any category the product is also filed under
        // (the demo files every product under its L1 parent too, so a one-product leaf such as
        // "LEGO sets" still gets a row of building toys).
        foreach ([true, false] as $primaryOnly) {
            $missing = $limit - count($picked);
            if ($missing <= 0) {
                break;
            }

            $query = self::live()
                ->where('products.id', '!=', $product->id)
                ->whereNotIn('products.id', array_map(static fn (Product $p): int => $p->id, $picked));

            $primaryOnly
                ? $query->where('products.primary_category_id', $product->primary_category_id)
                : $query->whereIn('products.id', DB::table('category_product')
                    ->select('product_id')
                    ->whereIn('category_id', DB::table('category_product')->select('category_id')->where('product_id', $product->id)));

            $picked = [...$picked, ...$query
                ->orderByDesc('products.is_featured')
                ->orderByDesc('products.published_at')
                ->orderByDesc('products.id')
                ->limit($missing)
                ->with(ProductListing::RELATIONS)
                ->get()
                ->all()];
        }

        return new Collection(array_slice($picked, 0, $limit));
    }

    /**
     * Live products: published, with at least one active variant.
     *
     * @return Builder<Product>
     */
    private static function live(): Builder
    {
        return Product::query()->published()->whereHas('activeVariants');
    }
}
