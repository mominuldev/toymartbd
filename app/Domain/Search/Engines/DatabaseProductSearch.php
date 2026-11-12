<?php

declare(strict_types=1);

namespace App\Domain\Search\Engines;

use App\Domain\Catalog\Enums\ProductSort;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductListing;
use App\Domain\Search\DTOs\SearchHits;
use App\Domain\Search\DTOs\SearchRequest;
use App\Domain\Search\Enums\SearchSort;
use App\Domain\Search\Support\SearchText;
use App\Domain\Search\Support\Synonyms;
use Illuminate\Database\Eloquent\Builder;

/**
 * The degraded-mode search, used when Meilisearch is down or not configured: every word
 * of the query (or one of its synonyms) must appear in the English or Bangla name or the
 * brand name. No typo tolerance, no relevance ranking (featured first), no facet counts —
 * but the storefront keeps returning products instead of an error page.
 *
 * Filters reuse ProductListing::filtered(), so they mean exactly what they mean on a
 * category page.
 */
final readonly class DatabaseProductSearch implements ProductSearchEngine
{
    /** Words beyond this are ignored: each one adds a LIKE group. */
    private const int MAX_WORDS = 6;

    public function __construct(private ProductListing $listing) {}

    public function search(SearchRequest $request, bool $withFacets = true): SearchHits
    {
        $query = $this->query($request);
        $total = (clone $query)->toBase()->count();

        $sort = $request->sort === SearchSort::Relevance ? ProductSort::Featured : ProductSort::from($request->sort->value);
        foreach ($sort->orders() as [$column, $direction]) {
            $query->orderBy('products.'.$column, $direction);
        }

        $ids = $query->forPage($request->page, $request->perPage)->pluck('products.id')
            ->map(static fn (mixed $id): int => (int) $id)->values()->all();

        return new SearchHits($ids, $total, [], degraded: true);
    }

    public function count(SearchRequest $request): int
    {
        return $this->query($request)->toBase()->count();
    }

    /** @return Builder<Product> */
    private function query(SearchRequest $request): Builder
    {
        $query = $this->listing->filtered($request->filters);
        $synonyms = (array) config('search.synonyms', []);

        foreach (array_slice(SearchText::words($request->query), 0, self::MAX_WORDS) as $word) {
            // Both Unicode spellings of ড়/ঢ়/য়: stored names were never normalised.
            $terms = array_values(array_unique(array_merge(...array_map(SearchText::spellings(...), Synonyms::expand($word, $synonyms)))));
            $query->where(function (Builder $q) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.addcslashes($term, '%_\\').'%';
                    $q->orWhere('products.name', 'like', $like)
                        ->orWhere('products.name_bn', 'like', $like)
                        ->orWhereIn('products.brand_id', fn ($b) => $b->select('id')->from('brands')->where('name', 'like', $like));
                }
            });
        }

        return $query;
    }
}
