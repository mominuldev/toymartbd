<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Ordering\Actions\StartOnlinePayment;
use App\Domain\Ordering\Exceptions\OnlinePaymentNotAllowed;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\HandleSslcommerzNotification;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Services\OnlineGateways;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Storefront\Payments\StartOrderPaymentRequest;
use App\Http\Requests\Webhooks\SslcommerzNotificationRequest;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Online payment for the storefront (P4-PAY-02/03/04, docs/api/checkout.md "Online
 * payment"). COD stays the default and needs none of this (rule 33).
 */
final class PaymentController extends ApiController
{
    /**
     * `GET /payment-methods` — what checkout may offer now. COD is always first and default;
     * an online method appears only while its gateway is enabled (active, configured and —
     * in production — verified).
     */
    #[OpenApiResponse(403, 'The token lacks the `storefront:read` ability')]
    public function methods(Request $request, OnlineGateways $gateways): JsonResponse
    {
        $data = [['method' => PaymentMethod::Cod->value, 'online' => false, 'default' => true, 'sandbox' => false]];
        foreach ($gateways->enabled() as $method) {
            $data[] = ['method' => $method->value, 'online' => true, 'default' => false, 'sandbox' => $gateways->status($method)['sandbox']];
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    /**
     * `POST /orders/{number}/payment` — start, resume or retry the order's online payment.
     * Requires an `Idempotency-Key`. Never reserves stock again: the reservation made at
     * checkout holds until the order is paid or its payment window lapses.
     */
    #[HeaderParameter('X-Cart-Token', 'The token of the cart that placed the order.', type: 'string', required: true)]
    #[OpenApiResponse(404, 'Unknown number or wrong token (`not-found`)')]
    #[OpenApiResponse(409, '`conflict` with `reason`: not_online | gateway_unavailable | already_paid | order_closed | under_review | too_many_attempts')]
    public function start(StartOrderPaymentRequest $request, string $number, StartOnlinePayment $start): JsonResponse
    {
        $order = OrderController::ownedOrFail($number, $request->cartToken());

        try {
            $started = $start->handle($order, App::getLocale());
        } catch (OnlinePaymentNotAllowed $e) {
            return (new ProblemDetails(
                ProblemType::Conflict,
                409,
                (string) __('payments.not_allowed.'.$e->reason),
                extensions: ['reason' => $e->reason],
            ))->toResponse($request);
        }

        $payment = $started->payment;

        return response()->json(['data' => [
            'order_number' => $order->number,
            'payment' => [
                'status' => $payment->status->value,
                'method' => $payment->method->value,
                /** @var string|null */
                'expires_at' => $payment->expires_at?->toIso8601String(),
            ],
            /** `null` when no session could be opened — show the payment-failed page with retry. */
            'next_action' => $started->redirectUrl === null ? null : ['type' => 'redirect', 'url' => $started->redirectUrl],
            'resumed' => $started->resumed,
        ]]);
    }

    /**
     * `POST /payments/sslcommerz/return` — the customer's browser came back from SSLCommerz
     * and the storefront forwards the signed POST it received, untouched. Signature first,
     * then a re-query of SSLCommerz (never this body), then the order's status. The answer
     * tells the storefront where to send the customer; the page re-reads the order itself.
     */
    #[OpenApiResponse(401, 'The signature did not verify (`unauthenticated`) — nothing was written')]
    public function sslcommerzReturn(SslcommerzNotificationRequest $request, HandleSslcommerzNotification $handler): JsonResponse
    {
        $received = $handler->receive($request->raw(), HandleSslcommerzNotification::SOURCE_RETURN, $request->keptHeaders());
        $payment = $received['payment'];
        $order = $payment === null ? null : Order::query()->find($payment->order_id);

        return response()->json(['data' => [
            /** @var string|null */
            'order_number' => $order?->number,
            /** @var string|null */
            'payment_status' => $payment?->status->value,
            /** @var string|null */
            'order_payment_status' => $order?->payment_status->value,
        ]])->header('Cache-Control', 'no-store');
    }
}
