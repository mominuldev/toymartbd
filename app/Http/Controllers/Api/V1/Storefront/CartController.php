<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Cart\Actions\AddCartLine;
use App\Domain\Cart\Actions\ApplyCoupon;
use App\Domain\Cart\Actions\CreateCart;
use App\Domain\Cart\Actions\RemoveCartLine;
use App\Domain\Cart\Actions\RemoveCoupon;
use App\Domain\Cart\Actions\UpdateCartLine;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Cart\Services\CartResolver;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Cart\AddCartItemRequest;
use App\Http\Requests\Storefront\Cart\ApplyCouponRequest;
use App\Http\Requests\Storefront\Cart\CartTokenRequest;
use App\Http\Requests\Storefront\Cart\ShowCartRequest;
use App\Http\Requests\Storefront\Cart\UpdateCartItemRequest;
use App\Http\Resources\Storefront\CartResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The storefront cart (P4-CRT-01/02/04, docs/api/cart.md). Called only by the Next.js
 * server, which keeps the cart token in an httpOnly cookie and forwards it as
 * `X-Cart-Token`. Every figure comes from the pricing pipeline — the checkout code path.
 */
final class CartController extends ApiController
{
    /** `POST /cart` — start an empty cart; returns its token. */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function store(CartTokenRequest $request, CreateCart $createCart): JsonResponse
    {
        return (new CartResource($createCart->handle()))->response()->setStatusCode(201);
    }

    /** `GET /cart` — the priced cart; pass `district_id` (and `upazila_id`) to include delivery. */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token (`not-found`) — start a new one')]
    public function show(ShowCartRequest $request, CartResolver $carts, CartPricing $pricing): CartResource
    {
        return new CartResource($pricing->price($carts->find($request->cartToken()), $request->district(), $request->upazila()));
    }

    /**
     * `POST /cart/items` — add a variant (or raise its quantity). Without `X-Cart-Token`
     * a new cart is created and the response is 201.
     */
    #[HeaderParameter('X-Cart-Token', 'The cart token; omit to start a new cart.', type: 'string')]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token (`not-found`)')]
    #[OpenApiResponse(422, 'Quantity beyond stock, item unavailable, or line limits (`validation-error`)')]
    public function addItem(AddCartItemRequest $request, AddCartLine $addLine): JsonResponse
    {
        $priced = $addLine->handle($request->cartToken(), $request->variantId(), $request->quantity(), $request->recommendationSource());

        return (new CartResource($priced))->response()->setStatusCode($priced->cart->wasRecentlyCreated ? 201 : 200);
    }

    /** `PATCH /cart/items/{item}` — set a line's quantity. */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token, or the line is not on it (`not-found`)')]
    #[OpenApiResponse(422, 'Quantity beyond stock (`validation-error`)')]
    public function updateItem(UpdateCartItemRequest $request, int $item, UpdateCartLine $updateLine): CartResource
    {
        return new CartResource($updateLine->handle($request->requiredCartToken(), $item, $request->quantity()));
    }

    /** `DELETE /cart/items/{item}` — remove a line. */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token, or the line is not on it (`not-found`)')]
    public function removeItem(CartTokenRequest $request, int $item, RemoveCartLine $removeLine): CartResource
    {
        return new CartResource($removeLine->handle($request->requiredCartToken(), $item));
    }

    /** `POST /cart/coupon` — apply a coupon code (replaces any coupon already on the cart). */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token (`not-found`)')]
    #[OpenApiResponse(422, 'The coupon does not apply; `errors[0].message` says why (`validation-error`)')]
    public function applyCoupon(ApplyCouponRequest $request, ApplyCoupon $applyCoupon): CartResource
    {
        return new CartResource($applyCoupon->handle($request->requiredCartToken(), $request->code()));
    }

    /** `DELETE /cart/coupon` — remove the coupon. */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token (`not-found`)')]
    public function removeCoupon(CartTokenRequest $request, RemoveCoupon $removeCoupon): CartResource
    {
        return new CartResource($removeCoupon->handle($request->requiredCartToken()));
    }
}
