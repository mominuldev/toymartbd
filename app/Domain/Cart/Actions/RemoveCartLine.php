<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Cart\Services\CartResolver;
use Illuminate\Support\Facades\DB;

/** Remove a line from the cart. */
final readonly class RemoveCartLine
{
    public function __construct(
        private CartResolver $carts,
        private CartPricing $pricing,
    ) {}

    public function handle(string $token, int $itemId): PricedCart
    {
        return DB::transaction(function () use ($token, $itemId): PricedCart {
            $cart = $this->carts->lock($token);
            CartItem::query()->where('cart_id', $cart->id)->findOrFail($itemId)->delete();

            return $this->pricing->priceAndStore($cart);
        });
    }
}
