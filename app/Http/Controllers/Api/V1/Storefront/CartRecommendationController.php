<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Cart\Services\CartResolver;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Recommendations\RecommendationResolver;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Cart\CartTokenRequest;
use App\Http\Resources\Storefront\RecommendationRailResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * The cart's cross-sell rail (P10, PRD X-04, docs/api/recommendations.md). Cross-sell only —
 * a buyer in the cart has already decided, so never an upsell (rule 25b) — and never a product
 * already in the cart. Every card is in stock.
 */
final class CartRecommendationController extends ApiController
{
    /**
     * `GET /cart/recommendations` — up to 8 cards for the cart page, placement `cart_cross_sell`:
     * the cart lines' curated cross-sell pairs first, then products often bought with them.
     * An empty cart gets an empty rail.
     */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token (`not-found`) — start a new one')]
    public function index(CartTokenRequest $request, CartResolver $carts, RecommendationResolver $resolver): RecommendationRailResource
    {
        $cart = $carts->find($request->cartToken());

        /** @var list<int> $productIds the cart's products, in line order */
        $productIds = ProductVariant::query()
            ->join('cart_items', 'cart_items.variant_id', '=', 'product_variants.id')
            ->where('cart_items.cart_id', $cart->id)
            ->orderBy('cart_items.id')
            ->pluck('product_variants.product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new RecommendationRailResource($resolver->forCart($productIds), RecommendationPlacement::CartCrossSell);
    }
}
