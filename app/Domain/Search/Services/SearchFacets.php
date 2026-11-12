<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Catalog\DTOs\ProductListingFilters as F;
use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Catalog\Enums\Gender;
use App\Domain\Catalog\Enums\PriceBand;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Search\Support\FilterGroups;

/**
 * Meilisearch facet distributions → the exact `meta.facets` shape of
 * GET /storefront/products (ProductListing::facets()), so the storefront renders search
 * facets with the listing's own filter components. With no distribution (degraded mode)
 * every facet is empty and the storefront hides the sidebar sections.
 */
final class SearchFacets
{
    /**
     * @param  array<string, array<string, int>>  $facets  index field → value → count (each disjunctive)
     * @return array{
     *     category: list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>,
     *     brand: list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>,
     *     age_band: list<array{value: string, min_months: int, max_months: int|null, count: int, selected: bool}>,
     *     price_band: list<array{value: string, min: string, max: string|null, count: int, selected: bool}>,
     *     gender: list<array{value: string, count: int, selected: bool}>,
     *     rating: list<array{value: int, count: int, selected: bool}>,
     *     educational: array{count: int, selected: bool},
     * }
     */
    public function format(array $facets, F $filters): array
    {
        if ($facets === []) {
            return [
                'category' => [], 'brand' => [], 'age_band' => [], 'price_band' => [], 'gender' => [], 'rating' => [],
                'educational' => ['count' => 0, 'selected' => $filters->educational === true],
            ];
        }

        $field = static fn (string $facet): array => $facets[FilterGroups::FACET_FIELDS[$facet]] ?? [];

        return [
            'category' => $this->categories($field(F::CATEGORY), $filters),
            'brand' => $this->brands($field(F::BRAND), $filters),
            'age_band' => array_map(static fn (AgeBand $b): array => [
                'value' => $b->value, 'min_months' => $b->min(), 'max_months' => $b->max(),
                'count' => $field(F::AGE)[$b->value] ?? 0, 'selected' => in_array($b, $filters->ageBands, true),
            ], AgeBand::cases()),
            'price_band' => array_map(static fn (PriceBand $b): array => [
                'value' => $b->value, 'min' => $b->min(), 'max' => $b->max(),
                'count' => $field(F::PRICE)[$b->value] ?? 0, 'selected' => in_array($b, $filters->priceBands, true),
            ], PriceBand::cases()),
            'gender' => array_map(static fn (Gender $g): array => [
                'value' => $g->value, 'count' => $field(F::GENDER)[$g->value] ?? 0, 'selected' => in_array($g, $filters->genders, true),
            ], [Gender::Unisex, Gender::Boys, Gender::Girls]),
            'rating' => array_map(static function (int $stars) use ($field, $filters): array {
                $count = 0;
                foreach ($field(F::RATING) as $floor => $n) {
                    if ((int) $floor >= $stars) {
                        $count += $n;
                    }
                }

                return ['value' => $stars, 'count' => $count, 'selected' => $filters->minRating === $stars];
            }, [4, 3, 2, 1]),
            'educational' => ['count' => $field(F::EDUCATIONAL)['true'] ?? 0, 'selected' => $filters->educational === true],
        ];
    }

    /**
     * Children of the selected category (roots when none), as on a listing page.
     *
     * @param  array<string, int>  $counts
     * @return list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>
     */
    private function categories(array $counts, F $filters): array
    {
        $parent = $filters->category === null ? null : Category::query()->active()->where('slug', $filters->category)->first(['id']);
        if ($filters->category !== null && $parent === null) {
            return [];
        }

        return Category::query()->active()->where('parent_id', $parent?->id)->inTreeOrder()
            ->get(['id', 'name', 'name_bn', 'slug', '_lft', '_rgt'])
            ->map(static fn (Category $c): array => [
                'value' => $c->slug, 'label' => $c->name, 'label_bn' => $c->name_bn, 'count' => $counts[$c->slug] ?? 0, 'selected' => false,
            ])->values()->all();
    }

    /**
     * Brands with a hit under the other filters, by name, plus selected brands (count 0)
     * so a chip never vanishes — as ProductListing::brandFacet().
     *
     * @param  array<string, int>  $counts
     * @return list<array{value: string, label: string, label_bn: string|null, count: int, selected: bool}>
     */
    private function brands(array $counts, F $filters): array
    {
        $slugs = array_values(array_unique([...array_keys(array_filter($counts, static fn (int $n): bool => $n > 0)), ...$filters->brands]));
        if ($slugs === []) {
            return [];
        }

        return Brand::query()->active()->whereIn('slug', $slugs)->orderBy('name')->orderBy('id')->get(['slug', 'name', 'name_bn'])
            ->map(static fn (Brand $b): array => [
                'value' => $b->slug, 'label' => $b->name, 'label_bn' => $b->name_bn,
                'count' => $counts[$b->slug] ?? 0, 'selected' => in_array($b->slug, $filters->brands, true),
            ])->values()->all();
    }
}
