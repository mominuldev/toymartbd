<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reviews\DTOs\ReviewData;
use Closure;
use Illuminate\Validation\Validator;

/**
 * `POST /storefront/account/reviews` (P4-CAT-12) — the signed-in customer reviews a
 * live product. `order_item_id` is optional; when given it must be a delivered line
 * of this customer's own order for the product. `is_verified_purchase` is
 * prohibited: verification is derived on the server, never claimed by a client.
 */
final class SubmitReviewRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            /** The name shown on the review; defaults to the first name and last initial ("Nusrat J."). */
            'reviewer_name' => ['nullable', 'string', 'max:120'],
            'order_item_id' => ['nullable', 'integer', 'min:1'],
            'is_verified_purchase' => ['prohibited'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('product_id')) {
                return;
            }
            if (! Product::query()->published()->whereKey($this->integer('product_id'))->exists()) {
                $validator->errors()->add('product_id', 'This product is not available to review.');
            }
        }];
    }

    public function toData(): ReviewData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return new ReviewData(
            productId: (int) $v['product_id'],
            rating: (int) $v['rating'],
            title: $text($v['title'] ?? null),
            body: $text($v['body'] ?? null),
            reviewerName: $text($v['reviewer_name'] ?? null),
            orderItemId: isset($v['order_item_id']) ? (int) $v['order_item_id'] : null,
            locale: app()->getLocale() === 'bn' ? 'bn' : 'en',
        );
    }
}
