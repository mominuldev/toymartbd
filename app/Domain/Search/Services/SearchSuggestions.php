<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Search\DTOs\SearchRequest;
use App\Domain\Search\Engines\DatabaseProductSearch;
use App\Domain\Search\Engines\MeilisearchProductSearch;
use App\Domain\Search\Exceptions\SearchUnavailable;
use App\Domain\Search\Support\FilterGroups;
use App\Domain\Search\Support\MeilisearchClients;
use App\Domain\Search\Support\SearchText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Header autocomplete (storefront spec §A6.2, Figma SearchSuggestions 115:887): up to
 * 5 products, 3 categories and 3 brands for a partial query, plus the total for the
 * "See all N results" footer. Categories and brands come from the same Meilisearch query's
 * facet counts, so they agree with the results page. Degrades like ProductSearch.
 */
final readonly class SearchSuggestions
{
    public const int PRODUCTS = 5;

    public const int CATEGORIES = 3;

    public const int BRANDS = 3;

    public function __construct(
        private MeilisearchClients $clients,
        private MeilisearchProductSearch $meilisearch,
        private DatabaseProductSearch $database,
        private ProductSearch $search,
    ) {}

    /**
     * @return array{
     *     products: Collection<int, Product>,
     *     categories: list<array{slug: string, name: string, name_bn: string|null, icon: string|null, count: int|null}>,
     *     brands: list<array{slug: string, name: string, name_bn: string|null, count: int|null}>,
     *     total: int,
     *     degraded: bool,
     * }
     */
    public function suggest(string $query): array
    {
        $request = new SearchRequest($query, perPage: self::PRODUCTS);

        if ($this->clients->enabled()) {
            try {
                $hits = $this->meilisearch->search($request);

                return [
                    'products' => $this->search->hydrate($hits->ids),
                    'categories' => $this->categories($hits->facets[FilterGroups::FACET_FIELDS['category']] ?? [], $hits->total),
                    'brands' => $this->brands($hits->facets[FilterGroups::FACET_FIELDS['brand']] ?? []),
                    'total' => $hits->total,
                    'degraded' => false,
                ];
            } catch (SearchUnavailable $e) {
                Log::warning('Search suggestions fell back to the database.', ['reason' => $e->getMessage()]);
            }
        }

        $hits = $this->database->search($request, withFacets: false);
        $like = '%'.addcslashes(SearchText::normalize($query), '%_\\').'%';

        return [
            'products' => $this->search->hydrate($hits->ids),
            'categories' => Category::query()->active()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('name_bn', 'like', $like))
                ->inTreeOrder()->limit(self::CATEGORIES)->get(['slug', 'name', 'name_bn', 'icon'])
                ->map(static fn (Category $c): array => ['slug' => $c->slug, 'name' => $c->name, 'name_bn' => $c->name_bn, 'icon' => $c->icon, 'count' => null])->values()->all(),
            'brands' => Brand::query()->active()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('name_bn', 'like', $like))
                ->orderBy('name')->limit(self::BRANDS)->get(['slug', 'name', 'name_bn'])
                ->map(static fn (Brand $b): array => ['slug' => $b->slug, 'name' => $b->name, 'name_bn' => $b->name_bn, 'count' => null])->values()->all(),
            'total' => $hits->total,
            'degraded' => true,
        ];
    }

    /**
     * The most specific matching categories: a category is skipped when it holds every hit
     * and a narrower matching one exists (the root "Toys" says nothing).
     *
     * @param  array<string, int>  $counts
     * @return list<array{slug: string, name: string, name_bn: string|null, icon: string|null, count: int|null}>
     */
    private function categories(array $counts, int $total): array
    {
        $counts = array_filter($counts, static fn (int $n): bool => $n > 0);
        if ($counts === []) {
            return [];
        }

        $categories = Category::query()->active()->whereIn('slug', array_keys($counts))->get(['slug', 'name', 'name_bn', 'icon', '_lft', '_rgt']);
        $rows = [];
        foreach ($categories as $c) {
            $narrower = $categories->contains(static fn (Category $o): bool => $o->_lft > $c->_lft && $o->_rgt < $c->_rgt);
            if ($counts[$c->slug] >= $total && $narrower) {
                continue;
            }
            $rows[] = ['slug' => $c->slug, 'name' => $c->name, 'name_bn' => $c->name_bn, 'icon' => $c->icon, 'count' => $counts[$c->slug]];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return array_slice($rows, 0, self::CATEGORIES);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{slug: string, name: string, name_bn: string|null, count: int|null}>
     */
    private function brands(array $counts): array
    {
        $counts = array_filter($counts, static fn (int $n): bool => $n > 0);
        if ($counts === []) {
            return [];
        }

        $rows = Brand::query()->active()->whereIn('slug', array_keys($counts))->get(['slug', 'name', 'name_bn'])
            ->map(static fn (Brand $b): array => ['slug' => $b->slug, 'name' => $b->name, 'name_bn' => $b->name_bn, 'count' => $counts[$b->slug]])
            ->values()->all();
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return array_slice($rows, 0, self::BRANDS);
    }
}
