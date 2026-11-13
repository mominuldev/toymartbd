<?php

declare(strict_types=1);

namespace Tests\Feature\Tracking\Support;

use App\Domain\Tracking\Contracts\TrackingDestination;
use App\Domain\Tracking\DTOs\ConsentState;
use App\Domain\Tracking\DTOs\DispatchResult;
use App\Domain\Tracking\DTOs\TrackingEventData;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Exceptions\RetryableTrackingFailure;
use App\Domain\Tracking\Services\TrackingDestinations;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * A destination that records what it was asked to send. `sentPayload` deliberately carries
 * the raw-by-design fields (client_ip_address, client_user_agent) so the tests prove they are
 * redacted before storage, plus hashed user data.
 */
final class FakeTrackingDestination implements TrackingDestination
{
    /** @var list<TrackingEventData> */
    public array $sent = [];

    /** @var list<TrackingEventData> */
    public array $validated = [];

    public int $retryableFailures = 0;

    public function __construct(public string $destinationKey, public bool $configured = true) {}

    /** Bind fakes for both keys and switch server-side tracking on. @return array{ga4: self, meta: self} */
    public static function install(bool $configured = true): array
    {
        $fakes = ['ga4' => new self('ga4', $configured), 'meta' => new self('meta', $configured)];
        foreach ($fakes as $key => $fake) {
            app()->instance(TrackingDestinations::binding($key), $fake);
        }
        Setting::put(TrackingSettings::SERVER_SIDE_ENABLED, true, SettingGroup::Tracking);

        return $fakes;
    }

    public function key(): string
    {
        return $this->destinationKey;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function requiresConsent(ConsentState $c): bool
    {
        return $this->destinationKey === 'ga4' ? $c->analytics : $c->ads;
    }

    public function send(TrackingEventData $e, bool $testMode = false): DispatchResult
    {
        if ($this->retryableFailures > 0) {
            $this->retryableFailures--;

            throw new RetryableTrackingFailure('HTTP 503 from fake', $this->destinationKey, 503);
        }
        $this->sent[] = $e;

        return new DispatchResult(DispatchStatus::Sent, 200, '{"ok":true}', [
            'event_name' => $e->name->value,
            'event_id' => $e->eventId,
            'user_data' => [
                'em' => $e->email === null ? null : hash('sha256', strtolower($e->email)),
                'ph' => $e->phoneE164 === null ? null : hash('sha256', ltrim($e->phoneE164, '+')),
                'client_ip_address' => $e->clientIp,
                'client_user_agent' => $e->userAgent,
                'fbp' => $e->fbp,
            ],
        ]);
    }

    public function validate(TrackingEventData $e): DispatchResult
    {
        $this->validated[] = $e;

        return new DispatchResult(DispatchStatus::Sent, 200, '{"validationMessages":[]}');
    }
}
