<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

/** `POST /cart/restore` `{token}` — the token from a recovery SMS link (P7). */
final class RestoreCartRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],
        ];
    }
}
