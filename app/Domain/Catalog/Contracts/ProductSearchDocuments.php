<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Contracts;

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Collection;

/**
 * Builds a product's search-index document (P4-CAT-14). Declared in Catalog so the
 * Product model can be Scout-searchable without depending on the Search or Inventory
 * domains (deptrac); App\Domain\Search\Documents\ProductDocumentBuilder implements it
 * and App\Providers\SearchServiceProvider binds it.
 */
interface ProductSearchDocuments
{
    /**
     * Loads, in bulk, everything `build()` needs for these products (relations, stock,
     * category ancestry), so indexing a batch costs a fixed number of queries.
     *
     * @param  Collection<int, Product>  $products
     */
    public function prepare(Collection $products): void;

    /**
     * Whether the product belongs in the index: active, published, not deleted, with at
     * least one active variant — the same rule as the storefront listing.
     */
    public function isSearchable(Product $product): bool;

    /** @return array<string, mixed> */
    public function build(Product $product): array;
}
