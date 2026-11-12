<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Cart\Services\CartStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Set a line's quantity. Lowering it is always allowed (even when stock has since
 * dropped below it, so the customer can fix the cart); raising it must stay in stock.
 */
final readonly class UpdateCartLine
{
    public function __construct(
        private CartResolver $carts,
        private CartStock $stock,
        private CartPricing $pricing,
    ) {}

    /** @throws ValidationException */
    public function handle(string $token, int $itemId, int $quantity): PricedCart
    {
        return DB::transaction(function () use ($token, $itemId, $quantity): PricedCart {
            $cart = $this->carts->lock($token);
            $item = CartItem::query()->where('cart_id', $cart->id)->with('variant.product')->findOrFail($itemId);

            if ($quantity > $item->quantity) {
                $this->stock->assertCanHold($item->variant, $quantity);
            }

            $item->quantity = $quantity;
            $item->save();

            return $this->pricing->priceAndStore($cart);
        });
    }
}
