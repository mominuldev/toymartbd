<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\ShipmentsBoard;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Ordering\Models\OrderReturn;
use App\Http\Resources\ApiResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The board's selected-shipment card (Figma B · 08): provider, consignment, weight, COD
 * collected of total, the charges as recorded, attempts, and the courier timeline newest
 * first — each event with its source (webhook | poll | manual | booking) and both times
 * (rule 24). `payload_keys` lists the stored payload's top-level keys only, never values
 * (rule 20). `outcome` appears for a partial delivery: what came back is what staff
 * recorded on the order's return (providers report the amount, not the lines); with no
 * return recorded yet, `recorded` is false and both lists are empty — never guessed.
 *
 * @property Shipment $resource
 */
#[SchemaName('ShipmentTracking')]
final class ShipmentTrackingResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $user = $request->user();
        /** @var Collection<int, CourierTrackingEvent> $events */
        $events = $s->getRelationValue('boardEvents') ?? collect();

        return [
            'id' => $s->id,
            'number' => $s->number,
            /** @var 'pending'|'submitted'|'booked'|'picked_up'|'in_transit'|'out_for_delivery'|'delivered'|'partially_delivered'|'returned'|'cancelled'|'on_hold'|'failed' */
            'status' => $s->status->value,
            'provider_status_raw' => $s->provider_status_raw,
            'provider' => ['code' => $s->provider->code, 'name' => $s->provider->name, 'name_bn' => $s->provider->name_bn],
            'consignment_id' => $s->consignment_id,
            'tracking_code' => $s->tracking_code,
            /** The courier's own tracking page, when the driver returned one. */
            'tracking_url' => $s->tracking_url,
            /** @var array{id: int, number: string, customer_name: string, phone: string, phone_masked: bool}|null */
            'order' => BoardParts::order($s, $request),
            'weight_grams' => $s->weight_grams,
            'item_count' => $s->item_count,
            'cod_amount' => $s->cod_amount->toDecimal(),
            'cod_collected' => $s->cod_collected?->toDecimal(),
            /** @var array{charge: string|null, cod_fee: string|null, return_charge: string|null, total_cost: string|null} */
            'charges' => [
                'charge' => $s->charge?->toDecimal(),
                'cod_fee' => $s->cod_fee?->toDecimal(),
                'return_charge' => $s->return_charge?->toDecimal(),
                'total_cost' => $s->total_cost?->toDecimal(),
            ],
            /** @var array{amount: string|null, basis: 'settled'|'recorded'|'estimate'|null} */
            'cost' => BoardParts::cost($s),
            'delivery_attempts' => $s->delivery_attempts,
            'submitted_at' => $s->pickup_requested_at?->toIso8601String(),
            'booked_at' => $s->booked_at?->toIso8601String(),
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'returned_at' => $s->returned_at?->toIso8601String(),
            /** The label route needs a consignment id and customers.view_pii. */
            'can_print_label' => $s->consignment_id !== null && $user instanceof User && $user->can('viewPii', Order::class),
            /** @var list<array{id: int, status: string, from_status: string|null, provider_status: string|null, message: string|null, location: string|null, occurred_at: string, received_at: string, source: string, payload_keys: list<string>}> */
            'events' => $events->map(static fn (CourierTrackingEvent $e): array => BoardParts::event($e) + [
                'from_status' => $e->from_status,
                'payload_keys' => is_array($e->payload) ? array_map(strval(...), array_keys($e->payload)) : [],
            ])->values()->all(),
            /** @var array{recorded: bool, cod_collected: string|null, delivered: list<array{name: string, name_bn: string|null, quantity: int, amount: string}>, came_back: list<array{name: string, name_bn: string|null, quantity: int, amount: string}>}|null */
            'outcome' => $s->status === ShipmentStatus::PartiallyDelivered ? $this->outcome($s) : null,
        ];
    }

    /** @return array{recorded: bool, cod_collected: string|null, delivered: list<array{name: string, name_bn: string|null, quantity: int, amount: string}>, came_back: list<array{name: string, name_bn: string|null, quantity: int, amount: string}>} */
    private function outcome(Shipment $s): array
    {
        /** @var Collection<int, OrderReturn> $returns */
        $returns = $s->getRelationValue('boardReturns') ?? collect();
        $order = $s->getRelationValue('orderSummary');
        /** @var array<int, int> $back */
        $back = [];
        foreach ($returns as $return) {
            foreach ($return->items as $item) {
                $back[$item->order_item_id] = ($back[$item->order_item_id] ?? 0) + $item->quantity;
            }
        }
        $line = static fn (OrderItem $item, int $qty): array => [
            'name' => $item->product_name,
            'name_bn' => $item->product_name_bn,
            'quantity' => $qty,
            'amount' => $item->unit_price->times($qty)->toDecimal(),
        ];

        $delivered = [];
        $cameBack = [];
        if ($back !== [] && $order instanceof Order) {
            foreach ($order->items as $item) {
                $returned = min($item->quantity, $back[$item->id] ?? 0);
                if ($item->quantity - $returned > 0) {
                    $delivered[] = $line($item, $item->quantity - $returned);
                }
                if ($returned > 0) {
                    $cameBack[] = $line($item, $returned);
                }
            }
        }

        return ['recorded' => $back !== [], 'cod_collected' => $s->cod_collected?->toDecimal(), 'delivered' => $delivered, 'came_back' => $cameBack];
    }
}
