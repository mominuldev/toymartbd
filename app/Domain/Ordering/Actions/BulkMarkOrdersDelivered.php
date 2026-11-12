<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Ordering\DTOs\BulkOrderOutcome;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;

/**
 * Mark many shipped orders delivered from the board (manual, P5): each through its own
 * {@see MarkOrderDelivered} — its own transaction — so an order that is not shipped is
 * reported as a `conflict` and the rest still go through. Never all-or-nothing.
 */
final readonly class BulkMarkOrdersDelivered
{
    public function __construct(private MarkOrderDelivered $deliver) {}

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
                $delivered = $this->deliver->handle($order, $userId);
                $outcomes[$order->id] = new BulkOrderOutcome($order->id, $order->number, BulkOrderOutcome::DONE, $delivered->status->value);
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
