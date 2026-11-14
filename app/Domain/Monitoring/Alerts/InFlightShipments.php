<?php

declare(strict_types=1);

namespace App\Domain\Monitoring\Alerts;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\Shipment;
use Carbon\CarbonImmutable;

/** Shipments still in a courier's hands (the statuses `toymart:couriers:poll` re-queries). */
final class InFlightShipments
{
    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (ShipmentStatus $s): string => $s->value, [
            ShipmentStatus::Submitted, ShipmentStatus::Booked, ShipmentStatus::PickedUp, ShipmentStatus::InTransit,
            ShipmentStatus::OutForDelivery, ShipmentStatus::OnHold, ShipmentStatus::PartiallyDelivered,
        ]);
    }

    /**
     * Provider codes holding at least one in-flight shipment last touched before $before.
     *
     * @return list<string>
     */
    public static function providersWithShipmentsOlderThan(CarbonImmutable $before): array
    {
        return Shipment::query()
            ->join('courier_providers', 'courier_providers.id', '=', 'shipments.provider_id')
            ->whereIn('shipments.status', self::statuses())
            ->where('shipments.updated_at', '<', $before)
            ->distinct()
            ->orderBy('courier_providers.code')
            ->pluck('courier_providers.code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->values()
            ->all();
    }
}
