<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Notification\Models\CartRecovery;
use Illuminate\Support\Facades\DB;

/**
 * The restore link from a recovery SMS (P7): token → the still-open cart it belongs to,
 * or null (unknown, expired, or the cart was ordered/merged/expired). The first use is
 * stamped (`restored_at`) for the report. The link can be opened again until it expires —
 * a shopper may tap it twice — but it never resolves once the cart has become an order.
 */
final class RedeemRecoveryLink
{
    public function handle(string $token): ?Cart
    {
        if (strlen($token) < 16 || strlen($token) > 64) {
            return null;
        }

        return DB::transaction(function () use ($token): ?Cart {
            $recovery = CartRecovery::query()->where('restore_token_hash', CartRecovery::hashToken($token))->lockForUpdate()->first();
            if ($recovery === null || $recovery->restore_expires_at === null || ! $recovery->restore_expires_at->isFuture()) {
                return null;
            }
            $cart = Cart::query()->open()->whereKey($recovery->cart_id)->first();
            if ($cart === null) {
                return null;
            }
            if ($recovery->restored_at === null) {
                $recovery->restored_at = now();
                $recovery->save();
            }

            return $cart;
        });
    }
}
