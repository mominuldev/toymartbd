<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\WishlistItem;

/** Unsave a toy. Removing one not on the list is a no-op, so a retried tap is harmless. */
final readonly class RemoveFromWishlist
{
    public function handle(Customer $customer, int $productId): void
    {
        WishlistItem::query()->where('customer_id', $customer->id)->where('product_id', $productId)->delete();
    }
}
