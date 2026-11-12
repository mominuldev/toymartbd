<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Cart\Services\CartStock;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add a variant to a cart — creating the cart when no token is given, so the first
 * add-to-cart is one round trip (`cart.wasRecentlyCreated` tells the caller to set
 * the cookie). Adding a variant already in the cart raises that line's quantity;
 * lines are never duplicated. The resulting quantity must be in stock.
 */
final readonly class AddCartLine
{
    public function __construct(
        private CartResolver $carts,
        private CartStock $stock,
        private CartPricing $pricing,
    ) {}

    /** @throws ValidationException quantity beyond stock, unsellable variant, line limits */
    public function handle(?string $token, int $variantId, int $quantity): PricedCart
    {
        return DB::transaction(function () use ($token, $variantId, $quantity): PricedCart {
            $cart = $token === null ? CreateCart::newCart() : $this->carts->lock($token);
            $variant = ProductVariant::query()->with('product')->findOrFail($variantId);

            $item = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variant->id)->first();
            if ($item === null && CartItem::query()->where('cart_id', $cart->id)->count() >= Cart::MAX_LINES) {
                throw ValidationException::withMessages(['variant_id' => __('cart.lines.max', ['max' => Cart::MAX_LINES])]);
            }

            $newQuantity = ($item->quantity ?? 0) + $quantity;
            $this->stock->assertCanHold($variant, $newQuantity);

            if ($item === null) {
                CartItem::query()->create([
                    'cart_id' => $cart->id,
                    'variant_id' => $variant->id,
                    'quantity' => $newQuantity,
                    'unit_price' => $variant->price,
                    'line_total' => $variant->price->times($newQuantity),
                    'added_at' => now(),
                ]);
            } else {
                $item->quantity = $newQuantity;
                $item->save();
            }

            return $this->pricing->priceAndStore($cart);
        });
    }
}
