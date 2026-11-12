<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

use App\Domain\Cart\Models\Cart;
use Illuminate\Validation\Rule;

/**
 * `POST /cart/items` `{ variant_id, quantity }`. Without `X-Cart-Token` a new cart is
 * created (201) and its `token` returned for the storefront to store. Stock and
 * sellability are checked by the Action (422 on `quantity` / `variant_id`).
 */
final class AddCartItemRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Cart::MAX_LINE_QUANTITY],
        ];
    }

    public function variantId(): int
    {
        return (int) $this->validated('variant_id');
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
