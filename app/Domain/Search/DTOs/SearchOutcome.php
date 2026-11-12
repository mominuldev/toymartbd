<?php

declare(strict_types=1);

namespace App\Domain\Search\DTOs;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * A storefront search, ready to render: the page of products (hydrated from MySQL in
 * rank order), facets in the listing's shape, related category refinements, and — for zero
 * results — spelling suggestions and best sellers.
 */
final readonly class SearchOutcome
{
    /**
     * @param  Collection<int, Product>  $products
     * @param  array{
     *     category: list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>,
     *     brand: list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>,
     *     age_band: list<array{value: string, min_months: int, max_months: int|null, count: int, selected: bool}>,
     *     price_band: list<array{value: string, min: string, max: string|null, count: int, selected: bool}>,
     *     gender: list<array{value: string, count: int, selected: bool}>,
     *     rating: list<array{value: int, count: int, selected: bool}>,
     *     educational: array{count: int, selected: bool},
     * }  $facets
     * @param  list<array{category: string, label: string, label_bn: string|null, count: int, selected: bool}>  $related
     * @param  list<array{query: string, count: int}>  $suggestions
     * @param  Collection<int, Product>  $bestSellers
     */
    public function __construct(
        public Collection $products,
        public int $total,
        public array $facets,
        public array $related,
        public array $suggestions,
        public Collection $bestSellers,
        public bool $degraded,
    ) {}
}
