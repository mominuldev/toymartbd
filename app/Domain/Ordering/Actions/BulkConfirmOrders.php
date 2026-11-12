<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;

/**
 * Confirm many orders from the board (P4-ORD-08 "partial failure reports per-order
 * results"). Each order is its own {@see TransitionOrder} — its own transaction and row
 * lock — so one order that moved under us (already confirmed, cancelled by a colleague)
 * is reported as a `conflict` and the rest still go through. Orders are visited in
 * ascending id so two overlapping bulk runs take their locks in the same order.
 */
final readonly class BulkConfirmOrders
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
                $confirmed = $this->transition->handle($order, OrderStatus::Confirmed, $userId);
                $outcomes[$order->id] = new BulkOrderOutcome($order->id, $order->number, BulkOrderOutcome::DONE, $confirmed->status->value);
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
