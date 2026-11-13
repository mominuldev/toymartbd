<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Listeners\Courier\RecordCourierCharge;
use App\Domain\Finance\Listeners\Courier\RecordCourierCodFee;
use App\Domain\Finance\Listeners\Courier\RecordCourierReturnInLedger;
use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Contracts\ShippableOrders;
use App\Domain\Fulfilment\Events\ShipmentBooked;
use App\Domain\Fulfilment\Events\ShipmentDelivered;
use App\Domain\Fulfilment\Events\ShipmentPartiallyDelivered;
use App\Domain\Fulfilment\Events\ShipmentReturned;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Domain\Ordering\Listeners\Fulfilment\DeliverOrderOnShipmentDelivered;
use App\Domain\Ordering\Listeners\Fulfilment\FlagOrderOnPartialDelivery;
use App\Domain\Ordering\Listeners\Fulfilment\ReturnOrderOnShipmentReturned;
use App\Domain\Ordering\Listeners\Fulfilment\ShipOrderOnShipmentBooked;
use App\Domain\Ordering\Queries\EloquentShippableOrders;
use App\Policies\ShipmentPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 6 courier wiring (docs/api/shipments.md): the driver registry (rule 5), Ordering's
 * implementation of Fulfilment's order read seam, the shipment policy, and the listeners
 * that turn shipment events into order and ledger changes (rule 2).
 *
 * The Ordering listeners are SYNCHRONOUS — they run inside the Fulfilment transaction that
 * dispatched the event, so a booked shipment and a shipped order (or a delivered parcel and
 * a delivered order) commit together (§4.2, §4.3). The Finance listeners are queued after
 * commit, like every other ledger writer.
 */
final class FulfilmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CourierRegistry::class, static function ($app): CourierRegistry {
            $registry = new CourierRegistry($app);

            /** @var array<string, class-string<CourierGateway>> $drivers */
            $drivers = (array) config('couriers.drivers', []);
            foreach ($drivers as $code => $driver) {
                $registry->register($code, $driver);
            }

            return $registry;
        });

        $this->app->bind(ShippableOrders::class, EloquentShippableOrders::class);
    }

    public function boot(): void
    {
        Gate::policy(Shipment::class, ShipmentPolicy::class);

        // Order side, synchronous, inside the shipment transaction.
        Event::listen(ShipmentBooked::class, ShipOrderOnShipmentBooked::class);
        Event::listen(ShipmentDelivered::class, DeliverOrderOnShipmentDelivered::class);
        Event::listen(ShipmentReturned::class, ReturnOrderOnShipmentReturned::class);
        Event::listen(ShipmentPartiallyDelivered::class, FlagOrderOnPartialDelivery::class);

        // Ledger side, queued after commit (§4.2–4.4).
        Event::listen(ShipmentBooked::class, RecordCourierCharge::class);
        Event::listen(ShipmentDelivered::class, RecordCourierCodFee::class);
        Event::listen(ShipmentReturned::class, RecordCourierReturnInLedger::class);
    }
}
