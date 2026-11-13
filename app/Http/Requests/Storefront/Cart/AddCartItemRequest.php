<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Enums\RecommendationPlacement;
use Illuminate\Validation\Rule;

/**
 * `POST /cart/items` `{ variant_id, quantity, recommendation_source? }`. Without `X-Cart-Token` a new cart is
 * created (201) and its `token` returned for the storefront to store. Stock and
 * sellability are checked by the Action (422 on `quantity` / `variant_id`).
 * `recommendation_source` is the placement the add came from (rule 25d), stamped onto
 * the order item at checkout.
 */
final class AddCartItemRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Cart::MAX_LINE_QUANTITY],
            'recommendation_source' => ['sometimes', 'nullable', 'string', Rule::enum(RecommendationPlacement::class)],
        ];
    }

    public function variantId(): int
    {
        return (int) $this->validated('variant_id');
    }

    public function recommendationSource(): ?RecommendationPlacement
    {
        $value = $this->validated('recommendation_source');

        return is_string($value) ? RecommendationPlacement::from($value) : null;
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
