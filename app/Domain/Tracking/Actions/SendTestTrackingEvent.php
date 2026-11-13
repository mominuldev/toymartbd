<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Actions;

use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Enums\TrackingEventName;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;
use App\Domain\Tracking\Services\TrackingDestinations;
use App\Domain\Tracking\Services\TrackingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Admin → Integrations → Tracking "Send test event": one synthetic `purchase` through the
 * destination's VALIDATION path ({@see \App\Domain\Tracking\Contracts\TrackingDestination::validate()}:
 * GA4's validation server / Meta's test_event_code), so it never counts as a real
 * conversion. Writes no `tracking_events` row and needs no consent (no visitor involved).
 * Synchronous on purpose — the owner is waiting for the answer — and outside any
 * transaction (rule 4). The caller rate-limits it.
 */
final readonly class SendTestTrackingEvent
{
    public function __construct(private TrackingDestinations $destinations, private TrackingSettings $settings) {}

    public function handle(string $destinationKey): DispatchResult
    {
        if (! in_array($destinationKey, TrackingDestinations::KEYS, true)) {
            throw new InvalidArgumentException('Unknown tracking destination.');
        }
        $destination = $this->destinations->get($destinationKey);
        if ($destination === null || ! $destination->isConfigured()) {
            return new DispatchResult(DispatchStatus::SkippedDisabled, error: 'Destination not configured or not enabled.');
        }

        if ($destinationKey === 'meta' && $this->settings->metaTestEventCode() === null) {
            // Without a test_event_code a Meta send would count as a REAL conversion.
            return new DispatchResult(DispatchStatus::Failed, error: 'Set a Meta test event code (Events Manager → Test events) before sending a test event.');
        }

        try {
            return $destination->validate(self::sample($this->settings));
        } catch (RetryableTrackingFailure $e) {
            return new DispatchResult(DispatchStatus::Failed, $e->httpStatus, null, [], $e->getMessage());
        }
    }

    /** A recognisable, PII-free test purchase. */
    public static function sample(TrackingSettings $settings): TrackingEventData
    {
        return new TrackingEventData(
            eventId: (string) Str::uuid(),
            name: TrackingEventName::Purchase,
            occurredAt: CarbonImmutable::now(),
            currency: 'BDT',
            value: '1.00',
            items: [['item_id' => 'TEST-SKU', 'item_name' => 'Test event', 'price' => '1.00', 'quantity' => 1]],
            transactionId: 'TEST-'.strtoupper(Str::random(8)),
            eventSourceUrl: rtrim((string) config('services.storefront.url', config('app.url')), '/').'/',
            clientIp: null,
            userAgent: 'ToyMartBD-TrackingTest/1.0',
            gaClientId: '555.1234567890',
            gaSessionId: null,
            fbp: null,
            fbc: null,
            phoneE164: null,
            email: null,
            firstName: null,
            lastName: null,
            city: null,
            externalId: 'tracking-test',
            consent: new ConsentState(true, true, $settings->consentMode(), $settings->consentPolicyVersion()),
        );
    }
}
