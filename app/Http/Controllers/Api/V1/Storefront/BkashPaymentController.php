<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Actions\HandleBkashCallback;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\Payments\BkashReturnRequest;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * bKash Tokenized Checkout's browser return (docs/api/checkout.md "Online payment"). bKash is
 * a driver behind the same seam as SSLCommerz and PayStation (rule 5); this is only its
 * callback, which is where bKash's capture (Execute Payment) happens.
 */
final class BkashPaymentController extends ApiController
{
    /**
     * `POST /payments/bkash/return` — the storefront forwards bKash's callback (`paymentID` as
     * `payment_id`, `status`, `signature`) and the order number. Requires an `Idempotency-Key`:
     * it may capture the payment (rule 17). The signature cannot be verified, so bKash is
     * re-queried; on `success` the payment is executed and queried again, and only the query's
     * answer is applied. `deferred: true` means bKash could not be reached yet and nothing was
     * decided — show "checking your payment" and let the order page re-read the status.
     */
    #[OpenApiResponse(404, 'No online bKash payment with that paymentID for that order — nothing was written')]
    public function return(BkashReturnRequest $request, HandleBkashCallback $handler): JsonResponse
    {
        $order = Order::query()->where('number', $request->orderNumber())->first() ?? throw new NotFoundHttpException;
        $payment = HandleBkashCallback::payment($order->id, $request->paymentId()) ?? throw new NotFoundHttpException;

        $received = $handler->receive($payment, $request->callbackStatus(), $request->kept(), $request->keptHeaders());
        $payment = $received['payment'];
        $order->refresh();

        return response()->json(['data' => [
            'order_number' => $order->number,
            'payment_status' => $payment->status->value,
            'order_payment_status' => $order->payment_status->value,
            /** True when bKash could not be reached yet; nothing was decided. */
            'deferred' => $received['outcome'] === 'deferred',
        ]])->header('Cache-Control', 'no-store');
    }
}
