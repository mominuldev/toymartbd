<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Payments;

use App\Http\Requests\Storefront\Cart\CartRequest;

/**
 * `POST /storefront/orders/{number}/payment` (P4-PAY-02/04) — start or retry an online
 * order's hosted-checkout payment. No body: ownership is the `X-Cart-Token` of the cart
 * that placed the order (as for the confirmation page); the amount is the order's own.
 */
final class StartOrderPaymentRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
