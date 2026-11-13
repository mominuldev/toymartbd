<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Fulfilment\DTOs\ShipmentRequestOutcome;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One order's result in a bulk shipment request: `requested` (a pending shipment was
 * created and queued for the courier), `conflict` (not shippable, or it already has an open
 * shipment — `message` says which) or `not_found`.
 *
 * @property ShipmentRequestOutcome $resource
 */
#[SchemaName('ShipmentBulkResult')]
final class ShipmentBulkResultResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $o = $this->resource;

        return [
            'order_id' => $o->orderId,
            /** @var 'requested'|'conflict'|'not_found' */
            'result' => $o->result,
            /** @var int|null */
            'shipment_id' => $o->shipment?->id,
            /** @var string|null */
            'shipment_number' => $o->shipment?->number,
            /** @var string|null */
            'message' => $o->message,
        ];
    }
}
