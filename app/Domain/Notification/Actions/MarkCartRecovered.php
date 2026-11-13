<?php

declare(strict_types=1);

namespace App\Domain\Notification\Actions;

use App\Domain\Notification\Enums\CartRecoveryStatus;
use App\Domain\Notification\Models\CartRecovery;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * An order was placed from a cart that had been reminded (P7): the recovery records the
 * order. Revenue is credited in the report only once that order is delivered. Idempotent.
 * A skipped (never messaged) cart that is later ordered is not "recovered".
 */
final class MarkCartRecovered
{
    public function handle(int $orderId): void
    {
        $order = Order::query()->find($orderId);
        if ($order === null || $order->cart_id === null) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $recovery = CartRecovery::query()->where('cart_id', $order->cart_id)->lockForUpdate()->first();
            if ($recovery === null || $recovery->status !== CartRecoveryStatus::Messaged || $recovery->recovered_order_id !== null) {
                return;
            }
            $recovery->recovered_order_id = $order->id;
            $recovery->recovered_at = now();
            $recovery->save();
        });
    }
}
