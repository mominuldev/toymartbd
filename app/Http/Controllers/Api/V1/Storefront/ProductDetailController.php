<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Services\ProductDetail;
use App\Domain\Inventory\Services\StockAvailability;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Storefront\StorefrontProductResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Storefront product page (P4-STO-05) and its "Similar toys" row (P4-STO-07). Cached by
 * the Next.js server under `product:{slug}` plus `products` (the related row is a
 * product grid), purged per docs/api/revalidation.md.
 */
final class ProductDetailController extends ApiController
{
    /** Cards in the related row: 4 on desktop, a swipe rail of up to 8 on mobile. */
    public const int RELATED = 8;

    /** Candidates fetched before the in-stock filter drops some (rule 25c). */
    private const int RELATED_CANDIDATES = 24;

    /**
     * `GET /products/{slug}` — one live product (active, published, with an active
     * variant): content in both languages, breadcrumbs, images, variant axes, every
     * active variant with its price, discount, saving and stock, and up to 8 in-stock
     * related product cards — curated `related` pairs first, then the same category.
     *
     * Slugs are ASCII and shared by both locales (rule 37).
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No live product has this slug (unknown, draft, archived, scheduled or without an active variant)')]
    public function show(string $slug, ProductDetail $detail, StockAvailability $stock): StorefrontProductResource
    {
        $product = $detail->find($slug) ?? throw (new ModelNotFoundException)->setModel(Product::class, [$slug]);

        $available = $stock->forVariants($product->activeVariants->map(static fn (ProductVariant $v): int => $v->id)->all());

        $candidates = $detail->relatedCandidates($product, self::RELATED_CANDIDATES);
        $inStock = array_flip($stock->inStockProductIds($candidates->modelKeys()));
        $related = new Collection($candidates->filter(static fn (Product $p): bool => isset($inStock[$p->id]))->take(self::RELATED)->values()->all());
        // Every related card is in stock by construction: tell the card resource, so it does not
        // look stock up again per card (its fallback is one query per product).
        $related->each(static fn (Product $p) => $p->setAttribute('listing_in_stock', 1));

        return new StorefrontProductResource($product, $available, $related);
    }
}
