<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Domain\Catalog\Models\Product;
use Closure;
use Illuminate\Validation\Validator;

/** `POST /storefront/account/wishlist` — save a live (published) product. */
final class WishlistRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('product_id')) {
                return;
            }
            if (! Product::query()->published()->whereKey($this->productId())->exists()) {
                $validator->errors()->add('product_id', (string) __('account.wishlist.unavailable'));
            }
        }];
    }

    public function productId(): int
    {
        return $this->integer('product_id');
    }
}
