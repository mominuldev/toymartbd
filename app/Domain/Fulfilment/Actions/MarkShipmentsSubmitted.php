<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentTransitions;
use Illuminate\Support\Facades\DB;

/**
 * A bulk submission the courier accepted: each shipment → `submitted`, with NO consignment
 * id (neither provider returns ids from bulk, rule 24d). The orders are not touched — no
 * stock moves and no charge is written until a webhook or the poll confirms each parcel
 * (ApplyCourierStatus completes it then). Only shipments still `pending` move.
 */
final readonly class MarkShipmentsSubmitted
{
    public function __construct(private ShipmentTransitions $transitions) {}

    /**
     * @param  list<int>  $shipmentIds
     * @return list<int> the ids actually moved
     */
    public function handle(array $shipmentIds): array
    {
        return DB::transaction(function () use ($shipmentIds): array {
            $ids = $shipmentIds;
            sort($ids);
            $moved = [];
            foreach (Shipment::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get() as $locked) {
                if ($locked->status !== ShipmentStatus::Pending) {
                    continue;
                }
                $this->transitions->move($locked, ShipmentStatus::Submitted, 'booking');
                $moved[] = $locked->id;
            }

            return $moved;
        });
    }
}
