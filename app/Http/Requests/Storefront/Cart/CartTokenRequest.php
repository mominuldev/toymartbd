<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

/** Cart endpoints whose only input is the `X-Cart-Token` header (create, remove line, remove coupon). */
final class CartTokenRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
