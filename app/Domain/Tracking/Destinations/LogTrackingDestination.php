<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Destinations;

use App\Domain\Tracking\Contracts\TrackingDestination;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Enums\DispatchStatus;
use Psr\Log\LoggerInterface;

/**
 * Writes a PII-free line per event to the application log instead of calling a provider.
 * For local development, so the dispatch pipeline is observable without credentials.
 * Never logs a phone, email, name, city, IP, user agent or click id (rule 20).
 */
final readonly class LogTrackingDestination implements TrackingDestination
{
    public function __construct(private LoggerInterface $logger, private string $key = 'log') {}

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
        $summary = NullTrackingDestination::summary($e, $testMode);
        $this->logger->info('tracking.log_destination.event', ['destination' => $this->key] + $summary);

        return new DispatchResult(DispatchStatus::Sent, sentPayload: $summary);
    }

    public function validate(TrackingEventData $e): DispatchResult
    {
        return $this->send($e, true);
    }
}
