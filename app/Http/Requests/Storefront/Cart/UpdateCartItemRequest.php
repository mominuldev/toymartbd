<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

use App\Domain\Cart\Models\Cart;

/** `PATCH /cart/items/{item}` `{ quantity }` — 1…99; remove a line with DELETE. */
final class UpdateCartItemRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Cart::MAX_LINE_QUANTITY],
        ];
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
