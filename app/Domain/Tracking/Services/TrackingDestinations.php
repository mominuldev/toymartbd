<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Services;

use App\Domain\Tracking\Contracts\TrackingDestination;
use Illuminate\Contracts\Container\Container;

/**
 * Destination key → driver, resolved through the container (rule 5: adding a provider is
 * writing a driver and one line in config/tracking.php `destinations`). Each driver is
 * bound under `tracking.destination.{key}` by TrackingServiceProvider, so a test can swap
 * one for a fake with `app()->instance('tracking.destination.ga4', $fake)`.
 */
final readonly class TrackingDestinations
{
    /** Keys the dispatch job always reports on (the `tracking_events` status columns). */
    public const array KEYS = ['ga4', 'meta'];

    public function __construct(private Container $container) {}

    public static function binding(string $key): string
    {
        return 'tracking.destination.'.$key;
    }

    /** Null when no driver is registered for the key (not built yet, or unknown). */
    public function get(string $key): ?TrackingDestination
    {
        $binding = self::binding($key);
        if (! $this->container->bound($binding)) {
            return null;
        }
        $destination = $this->container->make($binding);

        return $destination instanceof TrackingDestination ? $destination : null;
    }
}
