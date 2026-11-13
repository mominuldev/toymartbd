<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Contracts;

use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;

/**
 * A server-side analytics / ads destination (rule 5: one driver per provider; nothing
 * outside the driver knows the provider's wire format).
 */
interface TrackingDestination
{
    /** 'ga4' | 'meta' | 'null' | 'log' */
    public function key(): string;

    /** Credentials present and the destination is enabled. */
    public function isConfigured(): bool;

    /** True when the given consent allows sending (ga4 → analytics, meta → ads). */
    public function requiresConsent(ConsentState $c): bool;

    /**
     * Never throws on a 4xx (returns a Failed result). Throws only for a retryable
     * failure: connection error, 429 or 5xx.
     *
     * @throws RetryableTrackingFailure
     */
    public function send(TrackingEventData $e, bool $testMode = false): DispatchResult;

    /**
     * Admin "Send test event": GA4 validation server / Meta test_event_code.
     *
     * @throws RetryableTrackingFailure
     */
    public function validate(TrackingEventData $e): DispatchResult;
}
