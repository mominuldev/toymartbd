<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\Models\Review;
use App\Domain\Catalog\Reviews\Services\RatingAggregates;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Review\ProductReviewsQuery;
use App\Http\Resources\Storefront\StorefrontReviewResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Approved reviews on the product page (P4-CAT-12). Cached by the Next.js server under
 * `product:{slug}`: moderating a review fires ProductChanged, which purges it.
 */
final class ReviewController extends ApiController
{
    /**
     * `GET /products/{slug}/reviews` — approved reviews, newest first, cursor-paginated,
     * with `meta.summary` = `{ rating_avg, rating_count, distribution: {"5": n … "1": n} }`
     * (the same figures as the product's `rating_avg` / `rating_count`).
     */
    #[OpenApiResponse(404, 'No live product has this slug')]
    public function index(ProductReviewsQuery $request, string $slug, RatingAggregates $aggregates): AnonymousResourceCollection
    {
        $product = Product::query()->published()->where('slug', $slug)->first()
            ?? throw (new ModelNotFoundException)->setModel(Product::class, [$slug]);

        $reviews = Review::query()->approved()
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->cursorPaginate($request->perPage())
            ->withQueryString();

        return StorefrontReviewResource::collection($reviews)->additional(['meta' => ['summary' => [
            /** @var string|null */
            'rating_avg' => $product->rating_avg === null ? null : number_format((float) $product->rating_avg, 2, '.', ''),
            'rating_count' => $product->rating_count,
            'distribution' => $aggregates->distribution($product->id),
        ]]]);
    }
}
