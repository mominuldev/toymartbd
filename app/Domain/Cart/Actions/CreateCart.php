<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Cart\Services\CartResolver;
use Illuminate\Support\Facades\DB;

/** Start an empty cart with a fresh opaque token. */
final readonly class CreateCart
{
    public function __construct(private CartPricing $pricing) {}

    public function handle(?int $customerId = null): PricedCart
    {
        return DB::transaction(fn (): PricedCart => $this->pricing->priceAndStore(self::newCart($customerId)));
    }

    /** Insert a cart row. Only for use inside a cart Action's transaction. */
    public static function newCart(?int $customerId = null): Cart
    {
        $cart = new Cart(['token' => CartResolver::newToken(), 'customer_id' => $customerId]);
        $cart->extendLifetime();
        $cart->save();

        return $cart;
    }
}
