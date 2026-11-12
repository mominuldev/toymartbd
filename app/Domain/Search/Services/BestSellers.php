<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Catalog\DTOs\ProductListingFilters;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductListing;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Popular right now" — the best-sellers row under zero search results (storefront spec
 * B6.3, Figma 161:16310). Listable products only (ProductListing), card relations loaded.
 *
 * TODO(P5 orders): rank by units sold over the last 30 days once order history exists.
 * Until then: featured first, then most reviewed, then most viewed.
 */
final readonly class BestSellers
{
    public function __construct(private ProductListing $listing) {}

    /** @return Collection<int, Product> */
    public function top(int $limit = 4): Collection
    {
        return $this->listing->filtered(new ProductListingFilters)
            ->with(ProductListing::RELATIONS)
            ->orderByDesc('products.is_featured')
            ->orderByDesc('products.rating_count')
            ->orderByDesc('products.view_count')
            ->orderByDesc('products.id')
            ->limit($limit)
            ->get(['products.*']);
    }
}
