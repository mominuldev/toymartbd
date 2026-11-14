<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\BookingQueue;

use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use App\Http\Resources\Admin\ShipmentResource;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A shipment row on the booking queue's Submitted / Booked today / Failed tabs: the full
 * `Shipment` plus the recipient name and area for the row (no phone, no street — rule 20).
 * A `submitted` shipment has no consignment id until a webhook or poll confirms it
 * (rule 24d) — show "Submitted — awaiting ID", never "booked".
 *
 * @property Shipment $resource
 */
#[SchemaName('BookingQueueShipment')]
final class BookingQueueShipmentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $order = $s->getRelationValue('queueOrder');
        $order = $order instanceof Order ? $order : null;

        return [
            'shipment' => new ShipmentResource($s),
            /** @var string|null When the booking was handed to the courier without an id (bulk / uncertain). */
            'submitted_at' => $s->pickup_requested_at?->toIso8601String(),
            'recipient_name' => $order?->shipping_name,
            'district_name' => $order?->shipping_district,
            'upazila_name' => $order?->shipping_upazila,
        ];
    }
}
