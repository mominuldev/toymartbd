<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;

/**
 * Mark many orders packed from the board (P4-ORD-08): each → `ready_to_ship` through its
 * own {@see TransitionOrder} — its own transaction and row lock — so an order that cannot
 * move (still pending, on hold, already shipped) is reported as a `conflict` and the
 * rest still go through. Never all-or-nothing. Ascending id, like BulkConfirmOrders.
 */
final readonly class BulkMarkOrdersPacked
{
    public function __construct(private TransitionOrder $transition) {}

    /**
     * @param  list<int>  $orderIds
     * @return list<BulkOrderOutcome> one per requested id, in the order requested
     */
    public function handle(array $orderIds, ?int $userId = null): array
    {
        $orders = Order::query()->whereKey($orderIds)->orderBy('id')->get()->keyBy('id');

        $outcomes = [];
        foreach ($orders as $order) {
            try {
                $packed = $this->transition->handle($order, OrderStatus::ReadyToShip, $userId);
                $outcomes[$order->id] = new BulkOrderOutcome($order->id, $order->number, BulkOrderOutcome::DONE, $packed->status->value);
            } catch (InvalidOrderTransition $e) {
                $current = Order::query()->whereKey($order->id)->value('status');
                $outcomes[$order->id] = new BulkOrderOutcome(
                    $order->id,
                    $order->number,
                    BulkOrderOutcome::CONFLICT,
                    is_string($current) ? $current : $order->status->value,
                    $e->getMessage(),
                );
            }
        }

        return array_map(
            static fn (int $id): BulkOrderOutcome => $outcomes[$id] ?? new BulkOrderOutcome($id, null, BulkOrderOutcome::NOT_FOUND, null),
            $orderIds,
        );
    }
}
