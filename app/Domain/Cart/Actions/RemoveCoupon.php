<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Cart\Services\CartResolver;
use Illuminate\Support\Facades\DB;

/** Take the coupon off the cart (idempotent). */
final readonly class RemoveCoupon
{
    public function __construct(
        private CartResolver $carts,
        private CartPricing $pricing,
    ) {}

    public function handle(string $token): PricedCart
    {
        return DB::transaction(function () use ($token): PricedCart {
            $cart = $this->carts->lock($token);
            $cart->coupon_id = null;
            $cart->save();

            return $this->pricing->priceAndStore($cart);
        });
    }
}
