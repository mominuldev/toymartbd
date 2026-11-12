<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Order;

use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Models\Payment;
use App\Http\Requests\ApiFormRequest;

/**
 * `POST /orders/{order}/payments/{payment}/verify` (P4-PAY-03) — re-query the gateway for
 * an online payment's authoritative status. Needs orders.update (PaymentPolicy::verify).
 */
final class VerifyOrderPaymentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');
        $payment = $this->route('payment');

        return $order instanceof Order && $payment instanceof Payment && $payment->order_id === $order->id
            && ($this->user()?->can('verify', $payment) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
