<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\ShipmentsBoard;

use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One row of the shipments board (Figma B · 08). `submitted` is "Submitted — awaiting ID",
 * never "booked" (rule 24d). The last event is the courier's, with its source and time
 * (rule 24). `cost.basis`: settled (courier statement), recorded (booking charge + fees on
 * the shipment, not settled yet), estimate (rate card), or null (no basis — shown as "—").
 *
 * @property Shipment $resource
 */
#[SchemaName('ShipmentBoardRow')]
final class ShipmentBoardRowResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $event = $s->getRelationValue('boardLastEvent');

        return [
            'id' => $s->id,
            'number' => $s->number,
            /** @var array{id: int, number: string, customer_name: string, phone: string, phone_masked: bool}|null */
            'order' => BoardParts::order($s, $request),
            'provider' => ['code' => $s->provider->code, 'name' => $s->provider->name, 'name_bn' => $s->provider->name_bn],
            'consignment_id' => $s->consignment_id,
            /** @var 'pending'|'submitted'|'booked'|'picked_up'|'in_transit'|'out_for_delivery'|'delivered'|'partially_delivered'|'returned'|'cancelled'|'on_hold'|'failed' */
            'status' => $s->status->value,
            'provider_status_raw' => $s->provider_status_raw,
            /** @var array{id: int, status: string, provider_status: string|null, message: string|null, location: string|null, occurred_at: string, received_at: string, source: string}|null */
            'last_event' => $event instanceof CourierTrackingEvent ? BoardParts::event($event) : null,
            'submitted_at' => $s->pickup_requested_at?->toIso8601String(),
            'cod_amount' => $s->cod_amount->toDecimal(),
            /** @var array{amount: string|null, basis: 'settled'|'recorded'|'estimate'|null} */
            'cost' => BoardParts::cost($s),
            'delivery_attempts' => $s->delivery_attempts,
        ];
    }
}
