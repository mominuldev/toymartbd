<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Destinations;

use App\Domain\Tracking\Contracts\TrackingDestination;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Enums\DispatchStatus;

/**
 * Accepts everything and sends nothing. For CI and local development; no network, no log.
 * The sent payload is a PII-free summary so a stored row still shows what would have gone.
 */
final readonly class NullTrackingDestination implements TrackingDestination
{
    public function __construct(private string $key = 'null') {}

    public function key(): string
    {
        return $this->key;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function requiresConsent(ConsentState $c): bool
    {
        return $c->analytics;
    }

    public function send(TrackingEventData $e, bool $testMode = false): DispatchResult
    {
        return new DispatchResult(DispatchStatus::Sent, sentPayload: self::summary($e, $testMode));
    }

    public function validate(TrackingEventData $e): DispatchResult
    {
        return $this->send($e, true);
    }

    /**
     * PII-free description of an event: no phone, email, name, city, IP, user agent or
     * click id ever appears here (rule 20).
     *
     * @return array<string, mixed>
     */
    public static function summary(TrackingEventData $e, bool $testMode = false): array
    {
        return [
            'event_id' => $e->eventId,
            'event_name' => $e->name->value,
            'occurred_at' => $e->occurredAt->toIso8601String(),
            'currency' => $e->currency,
            'value' => $e->value,
            'transaction_id' => $e->transactionId,
            'item_ids' => array_map(static fn (array $i): string => $i['item_id'], $e->items),
            'test_mode' => $testMode,
        ];
    }
}
