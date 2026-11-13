<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Actions;

use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\TrackingClientContext;
use App\Domain\Tracking\Models\OrderTrackingContext;
use App\Domain\Tracking\Services\DispatchPolicy;

/**
 * Stores the shopper's browser context for an order (PRD K-02), called by the checkout
 * controller AFTER PlaceOrder has committed — never inside its transaction. Idempotent on
 * the order: a replayed checkout keeps the first context. The server-side `purchase` reads
 * it at dispatch time (the OrderPlaced listener is delayed so this row is usually there).
 *
 * Personal data (IP, user agent, click ids): never logged; blanked after
 * `tracking.context_retention_days` by `toymart:tracking:prune-contexts`.
 */
final readonly class RecordOrderTrackingContext
{
    public function handle(int $orderId, TrackingClientContext $client, ConsentState $consent, ?string $eventSourceUrl = null): OrderTrackingContext
    {
        return OrderTrackingContext::query()->createOrFirst(
            ['order_id' => $orderId],
            [
                'client_ip' => self::cut($client->ip, 45),
                'user_agent' => self::cut($client->userAgent, 512),
                'ga_client_id' => self::cut($client->gaClientId, 100),
                'ga_session_id' => self::cut($client->gaSessionId, 100),
                'fbp' => self::cut($client->fbp, 255),
                'fbc' => self::cut($client->fbc, 255),
                'event_source_url' => self::cut($eventSourceUrl, 2048),
                'consent' => DispatchPolicy::consentToArray($consent),
                'created_at' => now(),
            ],
        );
    }

    private static function cut(?string $value, int $max): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $max);
    }
}
