<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Marketing\Exceptions\CouponRejected;
use App\Domain\Marketing\Exceptions\PromotionRejected;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Checkout\CheckoutNotReady;
use App\Domain\Ordering\Checkout\PriceChanged;
use App\Domain\Tracking\Actions\RecordOrderTrackingContext;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Http\Resources\Storefront\StorefrontOrderResource;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Checkout (P4-ORD-02 / P4-STO-09, docs/api/checkout.md). Guest checkout by default: the
 * customer is found or created by phone. Every figure is re-priced server-side; the
 * client's `expected_total` only decides whether to block with `price-changed`.
 */
final class CheckoutController extends ApiController
{
    /**
     * `POST /checkout` — place the cart as a cash-on-delivery order. Requires an
     * `Idempotency-Key`: a double submit returns the first order, never a second one.
     */
    #[HeaderParameter('X-Cart-Token', 'The cart token.', type: 'string', required: true)]
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    #[OpenApiResponse(404, 'No open cart has this token, or it was already ordered (`not-found`)')]
    #[OpenApiResponse(409, 'The total changed (`price-changed`), stock ran out (`insufficient-stock`), or the key is in flight (`request-in-progress`)')]
    #[OpenApiResponse(422, 'Invalid field, or the cart is not checkout-ready (`validation-error`)')]
    #[OpenApiResponse(429, 'More than 10 checkouts a minute for one cart')]
    public function store(CheckoutRequest $request, PlaceOrder $placeOrder, RecordOrderTrackingContext $recordContext): JsonResponse
    {
        try {
            $order = $placeOrder->handle($request->toData());
        } catch (PriceChanged $e) {
            return self::problem($request, ProblemType::PriceChanged, [['field' => 'expected_total', 'message' => $e->customerMessage()]]);
        } catch (InsufficientStock $e) {
            return self::problem($request, ProblemType::InsufficientStock, self::stockErrors($e));
        } catch (CheckoutNotReady $e) {
            return self::problem($request, ProblemType::ValidationError, $e->errors);
        } catch (CouponRejected $e) {
            return self::problem($request, ProblemType::ValidationError, [['field' => 'coupon', 'message' => $e->getMessage()]]);
        } catch (PromotionRejected $e) {
            // P7: a promotion limit, budget or flash allotment ran out since pricing — the total changed.
            return self::problem($request, ProblemType::PriceChanged, [['field' => 'expected_total', 'message' => $e->getMessage()]]);
        }

        // After PlaceOrder has committed — never inside its transaction (P7 tracking). A
        // failure here must never cost the customer their order: report and carry on.
        $this->recordTrackingContext($request, $recordContext, $order->id);

        return (new StorefrontOrderResource($order))->response()
            ->setStatusCode(201)
            ->header('Location', route('api.v1.storefront.orders.show', ['number' => $order->number], false));
    }

    private function recordTrackingContext(CheckoutRequest $request, RecordOrderTrackingContext $record, int $orderId): void
    {
        $client = $request->trackingContext();
        $consent = $request->trackingConsent();
        if ($client === null || $consent === null) {
            return;
        }
        try {
            $record->handle($orderId, $client, $consent, $request->trackingEventSourceUrl());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return list<array{field: string, message: string}> */
    private static function stockErrors(InsufficientStock $e): array
    {
        $errors = [];
        foreach ($e->lines as $variantId => $line) {
            $errors[] = [
                'field' => 'items.'.$variantId,
                'message' => $line['available'] > 0
                    ? (string) __('cart.stock.only_left', ['available' => $line['available']])
                    : (string) __('cart.stock.out_of_stock'),
            ];
        }

        return $errors;
    }

    /** @param  list<array{field: string, message: string}>  $errors */
    private static function problem(CheckoutRequest $request, ProblemType $type, array $errors): JsonResponse
    {
        return (new ProblemDetails($type, $type->status(), $type === ProblemType::ValidationError ? $type->defaultDetail() : $errors[0]['message'] ?? $type->defaultDetail(), $errors))
            ->toResponse($request);
    }
}
