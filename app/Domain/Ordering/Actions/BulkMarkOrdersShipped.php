<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;

/**
 * Mark many orders shipped from the board (manual dispatch, P5): each through its own
 * {@see MarkOrderShipped} — its own transaction, order lock and stock consumption — so an
 * order that cannot ship (not yet confirmed, on hold, already shipped) or whose stock no
 * longer covers it is reported as a `conflict` and the rest still go through. Never
 * all-or-nothing. Ascending id, like BulkMarkOrdersPacked. A bulk dispatch carries no
 * per-order tracking reference; the courier name applies to every order.
 */
final readonly class BulkMarkOrdersShipped
{
    public function __construct(private MarkOrderShipped $ship) {}

    /**
     * @param  list<int>  $orderIds
     * @return list<BulkOrderOutcome> one per requested id, in the order requested
     */
    public function handle(array $orderIds, ?string $courierName = null, ?int $userId = null): array
    {
        $orders = Order::query()->whereKey($orderIds)->orderBy('id')->get()->keyBy('id');

        $outcomes = [];
        foreach ($orders as $order) {
            try {
                $shipped = $this->ship->handle($order, $courierName, null, $userId);
                $outcomes[$order->id] = new BulkOrderOutcome($order->id, $order->number, BulkOrderOutcome::DONE, $shipped->status->value);
            } catch (InvalidOrderTransition|InsufficientStock $e) {
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
