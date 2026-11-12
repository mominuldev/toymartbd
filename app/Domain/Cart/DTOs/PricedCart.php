<?php

declare(strict_types=1);

namespace App\Domain\Cart\DTOs;

use App\Domain\Cart\Models\Cart;

/** A cart with its current price breakdown and per-variant availability — what the cart API returns. */
final readonly class PricedCart
{
    /** @param  array<int, int>  $available  variant id → units available now */
    public function __construct(
        public Cart $cart,
        public PriceBreakdown $breakdown,
        public array $available,
    ) {}
}
