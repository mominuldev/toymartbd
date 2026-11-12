<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

use App\Http\Requests\ApiFormRequest;

/**
 * Base for the storefront cart endpoints (P4-CRT-01). The cart is found by the opaque
 * token the Next.js server keeps in an httpOnly cookie and forwards in the
 * `X-Cart-Token` header — never in the URL, so it stays out of access logs. The
 * route's token ability authorises the request.
 */
abstract class CartRequest extends ApiFormRequest
{
    public const string TOKEN_HEADER = 'X-Cart-Token';

    public function authorize(): bool
    {
        return true;
    }

    public function cartToken(): ?string
    {
        $token = $this->header(self::TOKEN_HEADER);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** The token, or a 404 `not-found` exactly as for an unknown token. */
    public function requiredCartToken(): string
    {
        return $this->cartToken() ?? '';
    }
}
