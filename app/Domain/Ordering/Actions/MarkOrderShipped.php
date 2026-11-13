<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Manual dispatch (P5, until courier booking lands in Phase 6): staff hand the parcel to
 * a courier booked outside the system, or deliver it themselves. DATABASE-DESIGN §4.2's
 * "on success" block minus the shipment row and the courier call, in ONE transaction:
 *
 *   lock the order → assert the state machine allows → shipped (confirmed, processing or
 *   ready_to_ship; a second click is a 409 before anything moves) → consume the
 *   reservation (Inventory's ConsumeReservedStock: FOR UPDATE ascending variant id,
 *   reserved −= qty, `sale_out` movements, on_hand −= qty) → quantity_shipped = quantity
 *   → fulfillment_status fulfilled → status shipped + shipped_at, one customer-visible
 *   order_events row carrying the courier name / tracking reference → OrderShipped after
 *   commit (the shipped notification listens to it).
 *
 * The steps after the lock are {@see OrderDispatcher} — shared with courier booking
 * (Phase 6, ShipOrderForCourier) so stock consumption has one implementation. A manual
 * dispatch has no `shipments` row: the free-text courier name and tracking reference live
 * on the timeline row's `meta` (`manual: true`), and no `courier_charge` is written (the
 * charge is unknown here; a courier booking writes it).
 */
final readonly class MarkOrderShipped
{
    /** `stock_movements.reference_type` for a sale_out against an order. */
    public const string STOCK_REFERENCE = OrderDispatcher::STOCK_REFERENCE;

    public const int FIELD_MAX = 120;

    public function __construct(private OrderDispatcher $dispatcher) {}

    /**
     * @throws InvalidOrderTransition when the order cannot ship from its status (409)
     * @throws InsufficientStock when on_hand no longer covers a line (a miscount)
     */
    public function handle(
        Order $order,
        ?string $courierName = null,
        ?string $trackingReference = null,
        ?int $userId = null,
        ?string $note = null,
    ): Order {
        $courierName = self::clean($courierName);
        $trackingReference = self::clean($trackingReference);
        $note = self::clean($note);

        return DB::transaction(function () use ($order, $courierName, $trackingReference, $userId, $note): Order {
            /** @var Order $locked */
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);

            return $this->dispatcher->dispatch($locked, $userId, array_filter([
                'manual' => true,
                'courier_name' => $courierName,
                'tracking_reference' => $trackingReference,
            ], static fn (mixed $v): bool => $v !== null), $note);
        });
    }

    private static function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
