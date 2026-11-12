<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Cart\Models\Cart;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Storefront\OrderConfirmationRequest;
use App\Http\Requests\Storefront\OrderTrackRequest;
use App\Http\Resources\Storefront\StorefrontOrderResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's view of one order (P4-STO-10, docs/api/checkout.md): the confirmation
 * page (proved by the cart token that placed it) and public tracking (order number
 * **plus** phone digits, rule 22). Every miss is the same 404 — never a hint which part
 * was wrong.
 */
final class OrderController extends ApiController
{
    /** How long the placing cart's token keeps opening the confirmation page. */
    public const int CONFIRMATION_DAYS = 30;

    /** `GET /orders/{number}` — the confirmation page, for the cart that placed the order. */
    #[HeaderParameter('X-Cart-Token', 'The token of the cart that placed the order.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'Unknown number, wrong token, or older than 30 days (`not-found`)')]
    #[OpenApiResponse(429, 'Too many lookups')]
    public function show(OrderConfirmationRequest $request, string $number): StorefrontOrderResource
    {
        $order = Order::query()->where('number', strtoupper($number))->first();
        $token = $request->cartToken();

        $owns = $order !== null
            && $order->cart_id !== null
            && $token !== null
            && Str::isUuid($token)
            && $order->placed_at !== null
            && $order->placed_at->greaterThan(now()->subDays(self::CONFIRMATION_DAYS))
            && hash_equals((string) Cart::query()->whereKey($order->cart_id)->value('token'), strtolower($token));

        if (! $owns) {
            throw new NotFoundHttpException;
        }

        return new StorefrontOrderResource($order);
    }

    /**
     * `POST /orders/track` — public tracking by order number plus the last 4 digits of the
     * phone (or the full number). No street address in the answer.
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No order matches that number and phone (`not-found`) — same answer for either mismatch')]
    #[OpenApiResponse(422, 'A field is empty or malformed (`validation-error`)')]
    #[OpenApiResponse(429, 'More than 5 attempts in 10 minutes for this order number')]
    public function track(OrderTrackRequest $request): StorefrontOrderResource|JsonResponse
    {
        $order = Order::query()->with('customer')->where('number', $request->number())->first();

        $matches = $order !== null
            && ($request->phoneMatches($order->shipping_phone) || $request->phoneMatches($order->customer->phone));

        if (! $matches) {
            return ProblemDetails::make(ProblemType::NotFound, (string) __('checkout.track.not_found'))->toResponse($request);
        }

        return StorefrontOrderResource::forTracking($order);
    }
}
