<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Actions;

use App\Domain\Tracking\DTOs\TrackingEventRecord;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Services\DispatchPolicy;
use App\Support\Money;

/**
 * Writes one `tracking_events` row, idempotent on (event_id, event_name, source): a second
 * call with the same triple returns the existing row and reports `created = false`, so the
 * caller does not dispatch it twice. No transaction is needed — a single insert guarded by
 * the UNIQUE key ({@see \Illuminate\Database\Eloquent\Builder::createOrFirst()}).
 *
 * The stored `payload` carries no personal data (rule 20): items, value, transaction id and
 * page URL only. Client IP, user agent and click ids never reach this table.
 */
final readonly class RecordTrackingEvent
{
    /** @return array{event: TrackingEvent, created: bool} */
    public function handle(TrackingEventRecord $record): array
    {
        $event = TrackingEvent::query()->createOrFirst(
            [
                'event_id' => strtolower($record->eventId),
                'event_name' => $record->name->value,
                'source' => $record->source->value,
            ],
            [
                'customer_id' => $record->customerId,
                'session_id' => $record->sessionId,
                'order_id' => $record->orderId,
                'value' => $record->value === null ? null : Money::of($record->value)->toDecimal(),
                'currency' => $record->currency,
                'payload' => array_filter([
                    'transaction_id' => $record->transactionId,
                    'event_source_url' => self::stripQuery($record->eventSourceUrl),
                    'items' => $record->items,
                ], static fn (mixed $v): bool => $v !== null),
                'consent_state' => DispatchPolicy::consentToArray($record->consent),
                'occurred_at' => $record->occurredAt,
                'created_at' => now(),
            ],
        );

        return ['event' => $event, 'created' => $event->wasRecentlyCreated];
    }

    /**
     * A query string can carry an email, a phone or a token (`?email=…` in a campaign link),
     * so only scheme + host + path are stored. The driver still receives the full URL.
     */
    private static function stripQuery(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/');
    }
}
