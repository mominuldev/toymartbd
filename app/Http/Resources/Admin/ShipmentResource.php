<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use App\Http\Resources\ApiResource;
use App\Models\User;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A shipment for the admin (docs/api/shipments.md). Money as decimal strings. `submitted`
 * must be shown as "submitted", never "booked": a bulk-booked parcel has no consignment id
 * until the courier confirms it (rule 24d). Carries no customer phone or address (rule 20);
 * the delivery rider's phone is masked unless the user holds customers.view_pii.
 *
 * @property Shipment $resource
 */
#[SchemaName('Shipment')]
final class ShipmentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $order = $s->getRelationValue('orderSummary');
        $user = $request->user();
        $pii = $user instanceof User && $user->can('viewPii', Order::class);

        return [
            'id' => $s->id,
            'number' => $s->number,
            'order_id' => $s->order_id,
            /** @var array{id: int, number: string, status: string}|null */
            'order' => $order instanceof Order ? ['id' => $order->id, 'number' => $order->number, 'status' => $order->status->value] : null,
            'provider' => ['code' => $s->provider->code, 'name' => $s->provider->name, 'name_bn' => $s->provider->name_bn],
            /** @var 'pending'|'submitted'|'booked'|'picked_up'|'in_transit'|'out_for_delivery'|'delivered'|'partially_delivered'|'returned'|'cancelled'|'on_hold'|'failed' */
            'status' => $s->status->value,
            'provider_status_raw' => $s->provider_status_raw,
            'consignment_id' => $s->consignment_id,
            'tracking_code' => $s->tracking_code,
            'tracking_url' => $s->tracking_url,
            'courier_location_id' => $s->courier_location_id,
            'weight_grams' => $s->weight_grams,
            'item_count' => $s->item_count,
            'cod_amount' => $s->cod_amount->toDecimal(),
            'declared_value' => $s->declared_value->toDecimal(),
            'charge' => $s->charge?->toDecimal(),
            'cod_fee' => $s->cod_fee?->toDecimal(),
            'return_charge' => $s->return_charge?->toDecimal(),
            'total_cost' => $s->total_cost?->toDecimal(),
            'cod_collected' => $s->cod_collected?->toDecimal(),
            'delivery_attempts' => $s->delivery_attempts,
            'booking_attempts' => $s->booking_attempts,
            /** @var string|null machine-readable: unverified_provider, inactive_provider, unmapped_location, ambiguous_location, provider_refused, transport, order_not_shippable, completion_failed */
            'failure_code' => $s->failure_code,
            'error_message' => $s->error_message,
            /** In the retry queue: failed, or booked with the courier but the order not yet marked shipped. */
            'needs_attention' => $s->status->value === 'failed' || ($s->dispatched_at === null && $s->failure_code === Shipment::FAILURE_COMPLETION),
            /** The order was marked shipped for this shipment (stock consumed, courier charge booked). */
            'dispatched' => $s->dispatched_at !== null,
            'note' => $s->note,
            /**
             * The delivery rider, recorded by staff when the rider calls (neither courier's API
             * returns it). `phone` is E.164 with customers.view_pii, else masked (`017****5678`);
             * `phone_masked` says which. Null when nobody has recorded one.
             *
             * @var array{name: string|null, phone: string|null, phone_masked: bool, source: 'manual'|'courier'|null, recorded_at: string|null}|null
             */
            'rider' => $s->rider_name === null && $s->rider_phone === null ? null : [
                'name' => $s->rider_name,
                'phone' => $s->rider_phone === null ? null : ($pii ? $s->rider_phone : (PhoneNumber::tryParse($s->rider_phone)?->masked() ?? '***')),
                'phone_masked' => ! $pii,
                'source' => $s->rider_source,
                'recorded_at' => $s->rider_recorded_at?->toIso8601String(),
            ],
            'booked_at' => $s->booked_at?->toIso8601String(),
            'dispatched_at' => $s->dispatched_at?->toIso8601String(),
            'picked_up_at' => $s->picked_up_at?->toIso8601String(),
            'delivered_at' => $s->delivered_at?->toIso8601String(),
            'returned_at' => $s->returned_at?->toIso8601String(),
            'cancelled_at' => $s->cancelled_at?->toIso8601String(),
            'last_attempt_at' => $s->last_attempt_at?->toIso8601String(),
            'created_at' => $s->created_at->toIso8601String(),
        ];
    }
}
