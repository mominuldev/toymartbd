<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Catalog\DTOs\ProductListingFilters as F;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductListing;
use App\Domain\Search\DTOs\SearchHits;
use App\Domain\Search\DTOs\SearchOutcome;
use App\Domain\Search\DTOs\SearchRequest;
use App\Domain\Search\Engines\DatabaseProductSearch;
use App\Domain\Search\Engines\MeilisearchProductSearch;
use App\Domain\Search\Engines\ProductSearchEngine;
use App\Domain\Search\Exceptions\SearchUnavailable;
use App\Domain\Search\Support\FilterGroups;
use App\Domain\Search\Support\MeilisearchClients;
use App\Domain\Search\Support\SearchText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The storefront search use case (P4-CAT-14 / P4-STO-04).
 *
 * Meilisearch answers when the Scout driver is `meilisearch`. If it is down, slow or
 * misconfigured the request does NOT fail: it is logged and answered by
 * DatabaseProductSearch (name / brand LIKE, no typo tolerance, no facets) with
 * `degraded = true`, so the storefront keeps selling. A 503 would put an error page in
 * front of every shopper who used the search box during an outage.
 */
final class ProductSearch
{
    /** Related-search chips (Figma RelatedSearches 161:15296 shows six). */
    public const int RELATED = 6;

    public const int BEST_SELLERS = 4;

    public function __construct(
        private readonly MeilisearchClients $clients,
        private readonly MeilisearchProductSearch $meilisearch,
        private readonly DatabaseProductSearch $database,
        private readonly SearchFacets $facets,
        private readonly BestSellers $bestSellers,
    ) {}

    public function search(SearchRequest $request): SearchOutcome
    {
        [$hits, $engine] = $this->hits($request);

        $products = $this->hydrate($hits->ids);
        $suggestions = [];
        $bestSellers = new Collection;

        if ($hits->total === 0) {
            $suggestions = (new SpellingSuggester($engine))->suggest($request);
            $bestSellers = $this->bestSellers->top(self::BEST_SELLERS);
            // Seeds the synonym table from real searches (storefront spec B6.3 "Logging").
            Log::info('Search returned no results.', [
                'query' => SearchText::redact(SearchText::normalize($request->query)),
                'filters' => array_keys(FilterGroups::expressions($request->filters)),
                'degraded' => $hits->degraded,
            ]);
        }

        return new SearchOutcome(
            $products,
            $hits->total,
            $this->facets->format($hits->facets, $request->filters),
            $this->related($hits->facets[FilterGroups::FACET_FIELDS[F::CATEGORY]] ?? [], $hits->total, $request->filters),
            $suggestions,
            $bestSellers,
            $hits->degraded,
        );
    }

    /** @return array{0: SearchHits, 1: ProductSearchEngine} */
    private function hits(SearchRequest $request): array
    {
        if ($this->clients->enabled()) {
            try {
                return [$this->meilisearch->search($request), $this->meilisearch];
            } catch (SearchUnavailable $e) {
                // Logged, not reported: an outage would flood the error tracker on every search.
                // The index-lag check and the health endpoint carry the alert.
                Log::warning('Search fell back to the database.', [
                    'reason' => $e->getMessage(),
                    'exception' => $e->getPrevious() === null ? null : $e->getPrevious()::class,
                ]);
            }
        }

        return [$this->database->search($request), $this->database];
    }

    /**
     * Cards for these ids, in hit order, with the card relations eager-loaded. Ids the
     * database no longer lists (archived since indexing) are dropped.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    public function hydrate(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $byId = Product::query()->published()->whereIn('id', $ids)->with(ProductListing::RELATIONS)->get()->keyBy('id');

        return new Collection(array_values(array_filter(array_map(static fn (int $id): ?Product => $byId->get($id), $ids))));
    }

    /**
     * Category refinements of this query, most results first: the "related searches" chips
     * (Figma 161:15296), each narrowing the search to one category. Umbrella categories that
     * contain every hit and have a narrower option are skipped — "Toys (37)" helps nobody.
     *
     * @param  array<string, int>  $counts  category slug → hits, category filter excluded
     * @return list<array{category: string, label: string, label_bn: string|null, count: int, selected: bool}>
     */
    private function related(array $counts, int $total, F $filters): array
    {
        $counts = array_filter($counts, static fn (int $n): bool => $n > 0);
        if ($counts === []) {
            return [];
        }

        $categories = Category::query()->active()->whereIn('slug', array_keys($counts))->get(['id', 'slug', 'name', 'name_bn', '_lft', '_rgt']);
        $all = max($total, max($counts));

        $out = [];
        foreach ($categories as $category) {
            $count = $counts[$category->slug];
            $hasNarrower = $categories->contains(static fn (Category $c): bool => $c->_lft > $category->_lft && $c->_rgt < $category->_rgt);
            if ($count >= $all && $hasNarrower && $category->slug !== $filters->category) {
                continue;
            }
            $out[] = ['category' => $category->slug, 'label' => $category->name, 'label_bn' => $category->name_bn, 'count' => $count, 'selected' => $category->slug === $filters->category];
        }

        usort($out, static fn (array $a, array $b): int => [$b['selected'], $b['count'], $a['label']] <=> [$a['selected'], $a['count'], $b['label']]);

        return array_slice($out, 0, self::RELATED);
    }
}
