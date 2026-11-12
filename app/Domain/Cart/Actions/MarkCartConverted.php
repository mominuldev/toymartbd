<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use Illuminate\Support\Facades\DB;

/**
 * DATABASE-DESIGN §4.1 "mark cart converted": the cart closes (its token stops
 * resolving) and records the order it became. Idempotent. Intended to run from
 * Ordering's order-placed event (cross-domain writes go through events, rule 2);
 * nested inside PlaceOrder's transaction it becomes a savepoint.
 */
final class MarkCartConverted
{
    public function handle(int $cartId, int $orderId): void
    {
        DB::transaction(function () use ($cartId, $orderId): void {
            $cart = Cart::query()->lockForUpdate()->find($cartId);
            if ($cart === null || $cart->converted_at !== null) {
                return;
            }

            $cart->converted_at = now();
            $cart->order_id = $orderId;
            $cart->save();
        });
    }
}
