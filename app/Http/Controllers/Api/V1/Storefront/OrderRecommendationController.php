<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Catalog\Enums\RecommendationPlacement;
use App\Domain\Catalog\Recommendations\RecommendationResolver;
use App\Domain\Ordering\Models\OrderItem;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\OrderConfirmationRequest;
use App\Http\Resources\Storefront\RecommendationRailResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * The order-confirmation cross-sell rail (P10, PRD X-06, docs/api/recommendations.md): the one
 * post-decision moment where a suggestion costs nothing. Same proof of ownership as the
 * confirmation page itself (`GET /orders/{number}`) — and the same 404 for every miss.
 */
final class OrderRecommendationController extends ApiController
{
    /**
     * `GET /orders/{number}/recommendations` — up to 4 in-stock cards, placement
     * `confirmation_cross_sell`, never a product already in the order.
     */
    #[HeaderParameter('X-Cart-Token', 'The token of the cart that placed the order.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'Unknown number, wrong token, or older than 30 days (`not-found`)')]
    #[OpenApiResponse(429, 'Too many lookups')]
    public function index(OrderConfirmationRequest $request, string $number, RecommendationResolver $resolver): RecommendationRailResource
    {
        $order = OrderController::ownedOrFail($number, $request->cartToken());

        /** @var list<int> $productIds the order's products, in line order */
        $productIds = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return new RecommendationRailResource($resolver->forOrder($productIds), RecommendationPlacement::ConfirmationCrossSell);
    }
}
