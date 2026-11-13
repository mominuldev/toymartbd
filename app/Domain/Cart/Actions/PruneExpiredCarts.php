<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use Illuminate\Support\Facades\DB;

/**
 * Stale cart cleanup (SYSTEM-ARCHITECTURE §7 scheduled tasks). Deletes carts that expired more
 * than {@see GRACE_DAYS} ago and never became an order — their lines go with them (FK cascade).
 *
 * Kept: a converted cart (`converted_at` / `order_id`: orders keep a plain `cart_id`), and any
 * cart with a `cart_recoveries` row (the abandoned-cart report reads it). Carts hold no stock
 * reservation — stock is reserved when an order is placed, and unpaid online orders are released
 * by `toymart:payments:expire` — so there is nothing to release here.
 *
 * Bounded per run ({@see handle()} `$limit`), oldest first, each chunk its own short transaction.
 */
final class PruneExpiredCarts
{
    public const int GRACE_DAYS = 7;

    public const int CHUNK = 500;

    /** @return int carts deleted */
    public function handle(int $limit = 5000): int
    {
        $cutoff = now()->subDays(self::GRACE_DAYS);
        $deleted = 0;

        while ($deleted < $limit) {
            $ids = Cart::query()
                ->where('expires_at', '<', $cutoff)
                ->whereNull('converted_at')
                ->whereNull('order_id')
                ->whereNotExists(static fn ($q) => $q->selectRaw('1')->from('cart_recoveries')->whereColumn('cart_recoveries.cart_id', 'carts.id'))
                ->orderBy('expires_at')
                ->limit(min(self::CHUNK, $limit - $deleted))
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            // `merged_into_cart_id` is a plain id (no FK): a guest cart merged into one of these keeps
            // its pointer and stays closed.
            $deleted += DB::transaction(static fn (): int => Cart::query()->whereIn('id', $ids)->delete());
        }

        return $deleted;
    }
}
