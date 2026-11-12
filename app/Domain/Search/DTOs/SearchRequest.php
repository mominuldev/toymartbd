<?php

declare(strict_types=1);

namespace App\Domain\Search\DTOs;

use App\Domain\Catalog\DTOs\ProductListingFilters;
use App\Domain\Search\Enums\SearchSort;

/**
 * A validated storefront search: the query, the listing filters (same names and
 * semantics as GET /storefront/products) and a 1-based page.
 */
final readonly class SearchRequest
{
    public function __construct(
        public string $query,
        public ProductListingFilters $filters = new ProductListingFilters,
        public SearchSort $sort = SearchSort::Relevance,
        public int $page = 1,
        public int $perPage = 24,
    ) {}

    public function withQuery(string $query): self
    {
        return new self($query, $this->filters, $this->sort, $this->page, $this->perPage);
    }
}
