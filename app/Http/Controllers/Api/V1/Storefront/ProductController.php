<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Services\ProductListing;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\ProductListingQuery;
use App\Http\Resources\Storefront\StorefrontProductCardResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Storefront product listings (P4-CAT-13): category pages, brand pages and any
 * other grid. Cached by the Next.js server under tag `products` (plus
 * `category:{slug}` / `brand:{slug}`), purged per docs/api/revalidation.md.
 */
final class ProductController extends ApiController
{
    public const int PER_PAGE = 24;

    /**
     * `GET /products` — active, published products with at least one active
     * variant, 24 per page by cursor, with disjunctive facet counts in
     * `meta.facets` (each facet ignores its own filter) and the result-set size in
     * `meta.total`.
     *
     * Lists are comma-separated. Unlisted parameters, filters or sorts and
     * malformed values are a 400. Availability ("in stock") arrives with the
     * inventory tables (P4-INV).
     */
    #[QueryParameter('filter[category]', 'Category slug; includes every active descendant category.', type: 'string')]
    #[QueryParameter('filter[brand]', 'Brand slugs, comma-separated (any of).', type: 'string', example: 'lego,hape')]
    #[QueryParameter('filter[age]', 'A child\'s age in months (0–216): age_min_months ≤ age ≤ age_max_months, both inclusive, null bounds open.', type: 'int')]
    #[QueryParameter('filter[age_band]', 'Age bands, comma-separated (any of): 0-12, 12-24, 24-48, 48-72, 72-96, 96-144, 144-.', type: 'string')]
    #[QueryParameter('filter[price_min]', 'Lowest listing price (the minimum active variant price), inclusive; a decimal string.', type: 'string')]
    #[QueryParameter('filter[price_max]', 'Highest listing price, inclusive; a decimal string.', type: 'string')]
    #[QueryParameter('filter[price_band]', 'Price bands, comma-separated (any of): 0-500, 500-1000, 1000-2500, 2500-5000, 5000- (min inclusive, max exclusive).', type: 'string')]
    #[QueryParameter('filter[gender]', 'Comma-separated (any of): unisex, boys, girls. `unisex` also matches products with no gender.', type: 'string')]
    #[QueryParameter('filter[rating]', 'Minimum average rating, 1–5.', type: 'int')]
    #[QueryParameter('filter[educational]', 'Only educational (true) or only non-educational (false) products.', type: "'true'|'false'|'1'|'0'")]
    #[QueryParameter('sort', 'Listing order.', type: "'featured'|'newest'|'price_asc'|'price_desc'|'rating'|'name'", default: 'featured')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted parameter, filter or sort, or an invalid filter value (`bad-request`)')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function index(Request $request, ProductListing $listing): AnonymousResourceCollection
    {
        $query = ProductListingQuery::fromRequest($request);

        $products = $listing->paginate($query->filters, $query->sort, self::PER_PAGE, $query->cursor)->withQueryString();

        return StorefrontProductCardResource::collection($products)->additional([
            'meta' => [
                'total' => $listing->count($query->filters),
                'sort' => $query->sort->value,
                'facets' => $listing->facets($query->filters),
            ],
        ]);
    }
}
