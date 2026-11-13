<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Notification\Contracts\SmsGateway;
use App\Domain\Notification\Contracts\WhatsAppGateway;
use App\Domain\Notification\Drivers\LogWhatsAppGateway;
use App\Domain\Notification\Drivers\NullWhatsAppGateway;
use App\Domain\Notification\Drivers\RecordingSmsGateway;
use App\Domain\Notification\Listeners\QueueOrderNotifications;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Models\NotificationPreference;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Notification\Services\SmsDrivers;
use App\Domain\Ordering\Events\OrderCancelled;
use App\Domain\Ordering\Events\OrderConfirmed;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderShipped;
use App\Policies\NotificationLogPolicy;
use App\Policies\NotificationPreferencePolicy;
use App\Policies\NotificationTemplatePolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Notification wiring (P4-NOT-01..03). Drivers sit behind contracts (rule 5): a new SMS
 * provider is a driver class plus one arm in SmsDrivers. The default everywhere
 * except a configured production is the log driver, which sends nothing.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // OTP and any other direct sender get the configured driver wrapped so every SMS
        // lands in notification_logs with its segments and cost (P4-NOT-01).
        $this->app->bind(SmsGateway::class, static function (Application $app): SmsGateway {
            $drivers = $app->make(SmsDrivers::class);

            return new RecordingSmsGateway($drivers->driver(), $drivers->name());
        });

        $this->app->bind(WhatsAppGateway::class, static function (Application $app): WhatsAppGateway {
            $driver = config('notifications.whatsapp.driver', 'log');

            return match ($driver) {
                'log' => $app->make(LogWhatsAppGateway::class),
                'null' => $app->make(NullWhatsAppGateway::class),
                default => throw new InvalidArgumentException('Unknown WhatsApp driver ['.(is_string($driver) ? $driver : '?').'].'),
            };
        });
    }

    public function boot(): void
    {
        // Every model has a Policy (rule 21); registered here so this domain does not
        // contend for AuthServiceProvider's map.
        Gate::policy(NotificationTemplate::class, NotificationTemplatePolicy::class);
        Gate::policy(NotificationLog::class, NotificationLogPolicy::class);
        Gate::policy(NotificationPreference::class, NotificationPreferencePolicy::class);

        // Ordering events are ShouldDispatchAfterCommit; the listener only writes log rows
        // and queues jobs — every provider call happens in a job (rule 4).
        Event::listen(OrderPlaced::class, [QueueOrderNotifications::class, 'onPlaced']);
        Event::listen(OrderConfirmed::class, [QueueOrderNotifications::class, 'onConfirmed']);
        Event::listen(OrderShipped::class, [QueueOrderNotifications::class, 'onShipped']);
        Event::listen(OrderDelivered::class, [QueueOrderNotifications::class, 'onDelivered']);
        Event::listen(OrderCancelled::class, [QueueOrderNotifications::class, 'onCancelled']);
        // P6: the courier's out-for-delivery move (Fulfilment event, after commit).
        Event::listen(\App\Domain\Fulfilment\Events\ShipmentStatusChanged::class, [QueueOrderNotifications::class, 'onShipmentStatusChanged']);
        // P8 batch 3: a removed child's name leaves the birthday-reminder payloads too.
        Event::listen(\App\Domain\Customer\Events\CustomerChildRemoved::class, \App\Domain\Notification\Listeners\ForgetRemovedChild::class);
    }
}
