<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Cart\Actions\SetCartContact;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Notification\Actions\RedeemRecoveryLink;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Storefront\Cart\RestoreCartRequest;
use App\Http\Requests\Storefront\Cart\SetCartContactRequest;
use App\Http\Resources\Storefront\CartResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Abandoned-cart recovery, storefront side (P7, docs/api/cart-recovery.md). Called only
 * by the Next.js server with its storefront token.
 */
final class CartRecoveryController extends ApiController
{
    /**
     * `PUT /cart/contact` — the phone a shopper left and whether they want one reminder
     * about this cart. Sending `marketing_consent: false` withdraws it. Answers 204.
     */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(404, 'No open cart has this token (`not-found`)')]
    #[OpenApiResponse(422, 'Invalid phone (`validation-error`)')]
    public function contact(SetCartContactRequest $request, SetCartContact $setContact): JsonResponse
    {
        $setContact->handle(
            $request->requiredCartToken(),
            $request->phone(),
            $request->boolean('marketing_consent'),
            $request->string('locale', 'bn')->toString(),
        );

        return response()->json(null, 204);
    }

    /**
     * `POST /cart/restore` — exchange a recovery link's token for its cart. Answers the
     * priced cart including its `token`, which the Next.js server stores in its httpOnly
     * cookie. An unknown or expired link, or a cart already ordered, is a 404.
     */
    #[OpenApiResponse(404, 'Unknown or expired link, or the cart is no longer open (`not-found`)')]
    public function restore(RestoreCartRequest $request, RedeemRecoveryLink $redeem, CartPricing $pricing): CartResource|JsonResponse
    {
        $cart = $redeem->handle($request->string('token')->toString());
        if ($cart === null) {
            return (new ProblemDetails(ProblemType::NotFound, 404, (string) __('cart.recovery.link_invalid')))->toResponse($request);
        }

        return new CartResource($pricing->price($cart));
    }
}
