<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Http\Requests\Storefront\Cart\CartRequest;

/**
 * `GET /storefront/orders/{number}` (confirmation page). The only input is the
 * `X-Cart-Token` of the cart that placed the order — proof of ownership.
 */
final class OrderConfirmationRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
