<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Events\ShipmentStatusChanged;
use App\Domain\Fulfilment\Exceptions\InvalidShipmentTransition;
use App\Domain\Fulfilment\Exceptions\ShipmentViolation;
use App\Domain\Fulfilment\Models\Shipment;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `shipments.status`: asserts the ShipmentStatus table, stamps the
 * status's timestamp column, saves, and emits ShipmentStatusChanged (after commit). No
 * transaction of its own — the calling Action has locked the row inside its transaction.
 */
final readonly class ShipmentTransitions
{
    /**
     * @param  array<string, mixed>  $attributes  columns written with the move
     *
     * @throws InvalidShipmentTransition
     */
    public function move(Shipment $locked, ShipmentStatus $to, string $source, array $attributes = []): Shipment
    {
        if (DB::transactionLevel() === 0) {
            throw ShipmentViolation::outsideTransaction();
        }

        $from = $locked->status;
        if (! $from->canTransitionTo($to)) {
            throw InvalidShipmentTransition::between($from, $to);
        }

        $locked->forceFill($attributes);
        $locked->status = $to;
        if (($column = $to->timestampColumn()) !== null && $locked->getAttribute($column) === null) {
            $locked->setAttribute($column, now());
        }
        $locked->save();

        ShipmentStatusChanged::dispatch($locked->id, $locked->order_id, $from->value, $to->value, $source);

        return $locked;
    }
}
