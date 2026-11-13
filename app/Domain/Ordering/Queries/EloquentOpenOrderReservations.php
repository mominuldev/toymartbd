<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Inventory\Contracts\OpenOrderReservations;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Ordering\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * What `stock_levels.reserved` should be (DATABASE-DESIGN §7 invariant 5), for Inventory's
 * nightly reconciliation: Σ (quantity − quantity_shipped) of the lines of every order whose
 * status still holds a reservation ({@see OrderStatus::holdsReservation()}) — the same
 * arithmetic as Order::reservedLines(), in one aggregate query rather than per order.
 *
 * Orders do not record a location: placement reserves at the default location
 * (StockReservations), so every open quantity is attributed there.
 */
final readonly class EloquentOpenOrderReservations implements OpenOrderReservations
{
    /** @return array<int, array<int, int>> location id → variant id → reserved quantity */
    public function quantities(): array
    {
        $open = array_values(array_map(
            static fn (OrderStatus $s): string => $s->value,
            array_filter(OrderStatus::cases(), static fn (OrderStatus $s): bool => $s->holdsReservation()),
        ));

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', $open)
            ->whereNotNull('order_items.variant_id')
            ->whereColumn('order_items.quantity', '>', 'order_items.quantity_shipped')
            ->groupBy('order_items.variant_id')
            ->orderBy('order_items.variant_id')
            ->selectRaw('order_items.variant_id as variant_id, SUM(order_items.quantity - order_items.quantity_shipped) as held')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $byVariant = [];
        foreach ($rows as $row) {
            $byVariant[(int) $row->variant_id] = (int) $row->held;
        }

        return [StockLocation::defaultId() => $byVariant];
    }
}
