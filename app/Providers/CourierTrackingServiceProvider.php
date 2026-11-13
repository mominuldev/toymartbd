<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Fulfilment\Events\ShipmentStatusChanged;
use App\Domain\Fulfilment\Listeners\RecordCourierTrackingEvent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * P6 courier status ingestion: the shipment history writer. (Webhooks, the reconciliation
 * poll and the courier report need no wiring beyond their routes and schedule.)
 */
final class CourierTrackingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(ShipmentStatusChanged::class, RecordCourierTrackingEvent::class);
    }
}
