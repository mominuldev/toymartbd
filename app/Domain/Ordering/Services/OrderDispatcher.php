<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Inventory\Actions\ConsumeReservedStock;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * The shared "goods leave the warehouse" step (DATABASE-DESIGN §4.2 "on success"), used by
 * BOTH manual dispatch ({@see \App\Domain\Ordering\Actions\MarkOrderShipped}) and courier
 * booking ({@see \App\Domain\Ordering\Actions\ShipOrderForCourier}), so stock consumption
 * has exactly one implementation. No transaction of its own (a Service, rule 3): the
 * calling Action has already locked the order inside its transaction.
 *
 *   assert the state machine allows → shipped → consume the reservation (Inventory's
 *   ConsumeReservedStock: FOR UPDATE ascending variant id, reserved −= qty, `sale_out`
 *   movements at the line's cost snapshot, on_hand −= qty) → quantity_shipped = quantity
 *   → fulfillment_status fulfilled → status shipped + shipped_at, one customer-visible
 *   order_events row → OrderShipped after commit.
 */
final readonly class OrderDispatcher
{
    /** `stock_movements.reference_type` for a sale_out against an order. */
    public const string STOCK_REFERENCE = 'order';

    public function __construct(
        private OrderTransitions $transitions,
        private ConsumeReservedStock $consume,
    ) {}

    /**
     * @param  Order  $locked  loaded `FOR UPDATE` by the caller, inside its transaction
     * @param  array<string, mixed>  $meta  stored on the timeline row (never a phone or address)
     *
     * @throws InvalidOrderTransition when the order cannot ship from its status (409)
     * @throws InsufficientStock when on_hand no longer covers a line (a miscount)
     */
    public function dispatch(Order $locked, ?int $userId, array $meta, ?string $note = null): Order
    {
        if (DB::transactionLevel() === 0) {
            throw OrderViolation::outsideTransaction();
        }

        OrderStateMachine::assertStatus($locked->status, OrderStatus::Shipped);
        $locked->loadMissing('items');

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
            meta: $meta + [
                'consumed' => array_map(static fn (array $l): array => ['variant_id' => $l['variant_id'], 'quantity' => $l['quantity']], $lines),
            ],
            description: $note,
        );
    }
}
