<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\ReconcileOnlinePayment;
use App\Domain\Payment\Models\Payment;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Admin\Order\VerifyOrderPaymentRequest;
use App\Http\Resources\Admin\OrderResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Staff re-check of an online payment (P4-PAY-03): asks the gateway for the authoritative
 * status (rule 19) and applies it through ReconcileOnlinePayment — a mismatch flags the
 * payment for review, never marks it paid. COD has nothing to ask.
 */
final class OrderPaymentController extends ApiController
{
    /** `POST /orders/{order}/payments/{payment}/verify` — 200 with the order. Requires an Idempotency-Key. */
    #[OpenApiResponse(409, 'Not an online payment (`conflict`)')]
    public function verify(VerifyOrderPaymentRequest $request, Order $order, Payment $payment, ReconcileOnlinePayment $reconcile): OrderResource|JsonResponse
    {
        if (! $payment->method->isOnline()) {
            return ProblemDetails::make(ProblemType::Conflict, (string) __('payments.verify.not_online'))->toResponse($request);
        }

        $reconcile->handle($payment);

        return new OrderResource($order->refresh()->load(OrderResource::RELATIONS));
    }
}
