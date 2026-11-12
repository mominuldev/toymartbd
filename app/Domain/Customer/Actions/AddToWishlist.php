<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\WishlistItem;
use Illuminate\Validation\ValidationException;

/**
 * Save a toy (P4-STO-11). Saving one already saved is a no-op, not an error; the unique
 * (customer, product) key makes two concurrent saves collapse to one row. Out-of-stock
 * toys may be saved — that is what "tell me when it is back" is for.
 */
final readonly class AddToWishlist
{
    public function handle(Customer $customer, int $productId): WishlistItem
    {
        $existing = WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $productId)->first();
        if ($existing !== null) {
            return $existing;
        }
        if (WishlistItem::query()->where('customer_id', $customer->id)->count() >= WishlistItem::MAX_ITEMS) {
            throw ValidationException::withMessages(['product_id' => (string) __('account.wishlist.limit', ['max' => WishlistItem::MAX_ITEMS])]);
        }

        // createOrFirst: a concurrent save of the same toy reads the winner's row.
        return WishlistItem::query()->createOrFirst(['customer_id' => $customer->id, 'product_id' => $productId]);
    }
}
