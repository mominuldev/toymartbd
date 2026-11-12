<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Search\Services\ProductSearch;
use App\Domain\Search\Services\SearchSuggestions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\SearchQuery;
use App\Http\Resources\Storefront\StorefrontProductCardResource;
use App\Http\Resources\Storefront\StorefrontSearchSuggestionsResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Storefront product search (P4-CAT-14, P4-STO-04) — docs/api/search.md.
 *
 * Not cached by tag on the Next.js side: results depend on free text, and the index is
 * kept fresh by queued sync (seconds), not by revalidation.
 */
final class SearchController extends ApiController
{
    /**
     * `GET /search` — products matching `q` (typo-tolerant; Bangla, Banglish and English
     * synonyms), 24 per page, as listing cards in relevance order.
     *
     * `filter[...]` takes exactly the listing's filters (GET /products) with the same
     * semantics; `meta.facets` has the listing's shape and disjunctive counts.
     * `meta.related_searches` are category refinements of the query. On zero results,
     * `meta.suggestions` offers corrected queries that do return products and
     * `meta.best_sellers` a fallback row.
     *
     * When Meilisearch is unavailable the endpoint still answers 200 from a simple database
     * name match with `meta.degraded: true` (no facets, no typo tolerance).
     */
    #[QueryParameter('q', 'Search text, 1–100 characters.', required: true, type: 'string', example: 'gari')]
    #[QueryParameter('filter[category]', 'Category slug; includes every active descendant category.', type: 'string')]
    #[QueryParameter('filter[brand]', 'Brand slugs, comma-separated (any of).', type: 'string')]
    #[QueryParameter('filter[age]', 'A child\'s age in months (0–216).', type: 'int')]
    #[QueryParameter('filter[age_band]', 'Age bands, comma-separated: 0-12, 12-24, 24-48, 48-72, 72-96, 96-144, 144-.', type: 'string')]
    #[QueryParameter('filter[price_min]', 'Lowest listing price, inclusive; a decimal string.', type: 'string')]
    #[QueryParameter('filter[price_max]', 'Highest listing price, inclusive; a decimal string.', type: 'string')]
    #[QueryParameter('filter[price_band]', 'Price bands, comma-separated: 0-500, 500-1000, 1000-2500, 2500-5000, 5000-.', type: 'string')]
    #[QueryParameter('filter[gender]', 'Comma-separated: unisex, boys, girls.', type: 'string')]
    #[QueryParameter('filter[rating]', 'Minimum average rating, 1–5.', type: 'int')]
    #[QueryParameter('filter[educational]', 'Only educational (true) or non-educational (false) products.', type: "'true'|'false'|'1'|'0'")]
    #[QueryParameter('sort', 'Result order.', type: "'relevance'|'featured'|'newest'|'price_asc'|'price_desc'|'rating'|'name'", default: 'relevance')]
    #[QueryParameter('page', '1-based page; at most 41 (Meilisearch returns the first 1000 hits).', type: 'int', default: 1)]
    #[OpenApiResponse(400, 'Missing or over-long `q`, an unlisted parameter, filter or sort, or an invalid value (`bad-request`)')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function index(Request $request, ProductSearch $search): AnonymousResourceCollection
    {
        $query = SearchQuery::fromRequest($request);
        $outcome = $search->search($query);

        $page = new LengthAwarePaginator($outcome->products, $outcome->total, $query->perPage, $query->page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return StorefrontProductCardResource::collection($page)->additional([
            'meta' => [
                'query' => $query->query,
                'sort' => $query->sort->value,
                'degraded' => $outcome->degraded,
                'facets' => $outcome->facets,
                /** @var list<array{category: string, label: string, label_bn: string|null, count: int, selected: bool}> */
                'related_searches' => $outcome->related,
                /** @var list<array{query: string, count: int}> */
                'suggestions' => $outcome->suggestions,
                'best_sellers' => StorefrontProductCardResource::collection($outcome->bestSellers),
            ],
        ]);
    }

    /**
     * `GET /search/suggest` — header autocomplete: up to 5 products, 3 categories and 3
     * brands for a partial query (2+ characters), and the total for "See all N results".
     * Rate-limited per client (`throttle:search-suggest`).
     */
    #[QueryParameter('q', 'Partial search text, 2–100 characters.', required: true, type: 'string', example: 'puz')]
    #[OpenApiResponse(400, 'Missing, too short or over-long `q`, or an unlisted parameter (`bad-request`)')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(429, 'Too many suggestion requests (`too-many-requests`)')]
    public function suggest(Request $request, SearchSuggestions $suggestions): StorefrontSearchSuggestionsResource
    {
        $query = SearchQuery::suggestFromRequest($request);

        return new StorefrontSearchSuggestionsResource(['query' => $query, ...$suggestions->suggest($query)]);
    }
}
