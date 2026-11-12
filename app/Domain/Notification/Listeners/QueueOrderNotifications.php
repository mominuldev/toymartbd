<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Notification\Actions\QueueOrderNotification;
use App\Domain\Ordering\Events\OrderCancelled;
use App\Domain\Ordering\Events\OrderConfirmed;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderShipped;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Order lifecycle → customer messages (P4-NOT-01/02). Queued on `notify`, so a fault in
 * notification code can never fail the order request that raised the event. The Ordering
 * events are dispatched after commit, so a rolled-back transition sends nothing.
 *
 * `order.out_for_delivery` has a template but no trigger yet: there is no courier status
 * event until shipments exist (Phase 6).
 */
final readonly class QueueOrderNotifications implements ShouldQueue
{
    public function __construct(private QueueOrderNotification $queue) {}

    public function viaQueue(): string
    {
        $queue = config('notifications.queue', 'notify');

        return is_string($queue) ? $queue : 'notify';
    }

    public function onPlaced(OrderPlaced $event): void
    {
        $this->queue->handle($event->orderId, 'order.placed');
    }

    public function onConfirmed(OrderConfirmed $event): void
    {
        $this->queue->handle($event->orderId, 'order.confirmed');
    }

    public function onShipped(OrderShipped $event): void
    {
        $this->queue->handle($event->orderId, 'order.shipped');
    }

    public function onDelivered(OrderDelivered $event): void
    {
        $this->queue->handle($event->orderId, 'order.delivered');
    }

    public function onCancelled(OrderCancelled $event): void
    {
        $this->queue->handle($event->orderId, 'order.cancelled');
    }
}
