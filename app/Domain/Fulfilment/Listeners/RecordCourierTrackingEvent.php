<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Listeners;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Events\ShipmentStatusChanged;
use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Appends the shipment's courier history (`courier_tracking_events`) for every status move,
 * whatever applied it. Runs synchronously after the move committed (the event is
 * ShouldDispatchAfterCommit), so `occurred_at` is the move's own time: the shipment's
 * timestamp column for the status where it has one (`booked_at`, `picked_up_at`,
 * `delivered_at`, `returned_at` — set from the courier's answer), else now.
 *
 * Statuses the customer never sees as a courier milestone (pending, failed) are skipped.
 */
final class RecordCourierTrackingEvent
{
    public function handle(ShipmentStatusChanged $event): void
    {
        $status = ShipmentStatus::tryFrom($event->to);
        if ($status === null || in_array($status, [ShipmentStatus::Pending, ShipmentStatus::Failed], true)) {
            return;
        }

        $shipment = Shipment::query()->find($event->shipmentId);
        if ($shipment === null) {
            return;
        }

        $column = $status->timestampColumn();
        $stamp = $column !== null ? $shipment->getAttribute($column) : null;
        $occurredAt = $stamp instanceof Carbon ? CarbonImmutable::instance($stamp) : CarbonImmutable::now();

        CourierTrackingEvent::query()->insertOrIgnore([[
            'shipment_id' => $shipment->id,
            'provider_id' => $shipment->provider_id,
            'normalised_status' => $status->value,
            'from_status' => mb_substr($event->from, 0, 24),
            'provider_status' => $shipment->provider_status_raw,
            'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s'),
            'received_at' => now()->utc()->format('Y-m-d H:i:s'),
            'source' => mb_substr($event->source, 0, 16),
            'created_at' => now(),
        ]]);
    }
}
