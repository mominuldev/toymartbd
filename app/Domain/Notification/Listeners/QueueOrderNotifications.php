<?php

declare(strict_types=1);

namespace App\Domain\Notification\Listeners;

use App\Domain\Fulfilment\Events\ShipmentStatusChanged;
use App\Domain\Notification\Actions\QueueOrderNotification;
use App\Domain\Notification\Models\NotificationLog;
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
 * `order.out_for_delivery` is triggered by the courier (P6): Fulfilment's
 * ShipmentStatusChanged with `to = out_for_delivery`, after commit. Only that transition is
 * queued ({@see ShouldQueue()}), and an order gets the message once — a second delivery
 * attempt does not message the customer again.
 */
final readonly class QueueOrderNotifications implements ShouldQueue
{
    private const string OUT_FOR_DELIVERY = 'out_for_delivery';

    public function __construct(private QueueOrderNotification $queue) {}

    public function viaQueue(): string
    {
        $queue = config('notifications.queue', 'notify');

        return is_string($queue) ? $queue : 'notify';
    }

    /** Queue only the events this listener acts on: every order event, and a shipment going out for delivery. */
    public function shouldQueue(object $event): bool
    {
        return ! $event instanceof ShipmentStatusChanged || $event->to === self::OUT_FOR_DELIVERY;
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

    public function onShipmentStatusChanged(ShipmentStatusChanged $event): void
    {
        $already = NotificationLog::query()
            ->where('notifiable_type', NotificationLog::NOTIFIABLE_ORDER)
            ->where('notifiable_id', $event->orderId)
            ->where('template', 'order.out_for_delivery')
            ->exists();

        if ($event->to === self::OUT_FOR_DELIVERY && ! $already) {
            $this->queue->handle($event->orderId, 'order.out_for_delivery');
        }
    }
}
