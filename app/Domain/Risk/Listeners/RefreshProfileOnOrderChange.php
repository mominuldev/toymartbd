<?php

declare(strict_types=1);

namespace App\Domain\Risk\Listeners;

use App\Domain\Fulfilment\Events\ShipmentStatusChanged;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderStatusTransition;
use App\Domain\Risk\Actions\RefreshRiskProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Order placed / delivered / cancelled / returned (any status transition), or a shipment
 * status move (failed delivery attempts, a refused parcel coming back) → rebuild the risk
 * profile of the order's phone(s): the shipping phone and, if different, the owning
 * customer's. Queued after commit; a replay is harmless (the profile is recomputed, not
 * incremented). Reads `orders` by id — the event carries no phone (rule 20).
 */
final readonly class RefreshProfileOnOrderChange implements ShouldQueue
{
    public function __construct(private RefreshRiskProfile $refresh) {}

    public function viaQueue(): string
    {
        return 'reports';
    }

    public function handle(OrderPlaced|OrderStatusTransition|ShipmentStatusChanged $event): void
    {
        $orderId = property_exists($event, 'orderId') ? $event->orderId : null;
        if (! is_int($orderId)) {
            return;
        }
        $this->refreshOrder($orderId);
    }

    public function refreshOrder(int $orderId): void
    {
        $order = DB::table('orders')->where('orders.id', $orderId)
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->first(['orders.shipping_phone', 'customers.phone as customer_phone']);
        if ($order === null) {
            return;
        }

        $phones = array_unique(array_filter(
            [$order->shipping_phone ?? null, $order->customer_phone ?? null],
            static fn (mixed $p): bool => is_string($p) && $p !== '',
        ));
        foreach ($phones as $phone) {
            $this->refresh->handle($phone);
        }
    }
}
