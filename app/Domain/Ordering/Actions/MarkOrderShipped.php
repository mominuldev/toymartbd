<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Actions;

use App\Domain\Inventory\Actions\ConsumeReservedStock;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Services\OrderStateMachine;
use App\Domain\Ordering\Services\OrderTransitions;
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
 * There is no `shipments` table yet, so the free-text courier name and tracking
 * reference live on the timeline row's `meta` (`manual: true`). No `courier_charge`
 * ledger entry is written — the charge is unknown here; Phase 6's booking writes it.
 */
final readonly class MarkOrderShipped
{
    /** `stock_movements.reference_type` for a sale_out against an order. */
    public const string STOCK_REFERENCE = 'order';

    public const int FIELD_MAX = 120;

    public function __construct(
        private OrderTransitions $transitions,
        private ConsumeReservedStock $consume,
    ) {}

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
            OrderStateMachine::assertStatus($locked->status, OrderStatus::Shipped);

            $lines = [];
            foreach ($locked->items as $item) {
                $open = $item->quantity - $item->quantity_shipped;
                if ($open <= 0) {
                    continue;
                }
                if ($item->variant_id !== null) {
                    $lines[] = ['variant_id' => $item->variant_id, 'quantity' => $open, 'unit_cost' => $item->cost_price_snapshot];
                }
                $item->quantity_shipped = $item->quantity;
                $item->save();
            }
            usort($lines, static fn (array $a, array $b): int => $a['variant_id'] <=> $b['variant_id']);
            $this->consume->handle($lines, self::STOCK_REFERENCE, $locked->id, $userId);

            if ($locked->fulfillment_status !== FulfillmentStatus::Fulfilled) {
                $this->transitions->transitionFulfillment($locked, FulfillmentStatus::Fulfilled, $userId);
            }

            return $this->transitions->transition(
                $locked,
                OrderStatus::Shipped,
                $userId,
                meta: array_filter([
                    'manual' => true,
                    'courier_name' => $courierName,
                    'tracking_reference' => $trackingReference,
                    'consumed' => array_map(static fn (array $l): array => ['variant_id' => $l['variant_id'], 'quantity' => $l['quantity']], $lines),
                ], static fn (mixed $v): bool => $v !== null),
                description: $note,
            );
        });
    }

    private static function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
