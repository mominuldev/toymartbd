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
use App\Domain\Ordering\Queries\OrderRiderContact;
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
 *
 * It names the RIDER's phone (owner decision 2026-11-13), read through Ordering's
 * {@see OrderRiderContact} (Notification never imports Fulfilment). With no rider recorded
 * the `order.out_for_delivery.no_rider` variant goes instead — no rider line, no hotline in
 * its place. Either variant counts as "sent once": recording the rider afterwards does not
 * send the message again.
 */
final readonly class QueueOrderNotifications implements ShouldQueue
{
    private const string OUT_FOR_DELIVERY = 'out_for_delivery';

    public const string TEMPLATE_OUT_FOR_DELIVERY = 'order.out_for_delivery';

    public const string TEMPLATE_OUT_FOR_DELIVERY_NO_RIDER = 'order.out_for_delivery.no_rider';

    public function __construct(private QueueOrderNotification $queue, private OrderRiderContact $riders) {}

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
        if ($event->to !== self::OUT_FOR_DELIVERY) {
            return;
        }

        $already = NotificationLog::query()
            ->where('notifiable_type', NotificationLog::NOTIFIABLE_ORDER)
            ->where('notifiable_id', $event->orderId)
            ->whereIn('template', [self::TEMPLATE_OUT_FOR_DELIVERY, self::TEMPLATE_OUT_FOR_DELIVERY_NO_RIDER])
            ->exists();

        if (! $already) {
            $this->queue->handle(
                $event->orderId,
                $this->riders->phoneFor($event->orderId) !== null ? self::TEMPLATE_OUT_FOR_DELIVERY : self::TEMPLATE_OUT_FOR_DELIVERY_NO_RIDER,
            );
        }
    }
}
