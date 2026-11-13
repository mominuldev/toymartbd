<?php

declare(strict_types=1);

use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Tracking\Support\FakeTrackingDestination;

/*
 * Storefront tracking API (PRD K-01..K-03) — docs/api/tracking.md §2.
 */

beforeEach(function (): void {
    $this->token = CartTestKit::storefrontToken();
});

/** @param  array<string, mixed>  $overrides */
function trackingEventBody(array $overrides = []): array
{
    return array_replace_recursive([
        'event_id' => (string) Str::uuid(),
        'event_name' => 'add_to_cart',
        'occurred_at' => now()->toIso8601String(),
        'event_source_url' => 'https://toymartbd.test/products/lego-city-bus?utm_source=fb&email=rahela%40example.com',
        'value' => '1250.00',
        'currency' => 'BDT',
        'items' => [['item_id' => 'LEGO-60335', 'item_name' => 'লেগো সিটি বাস', 'price' => '1250.00', 'quantity' => 1]],
        'client' => [
            'ip' => '103.4.145.27',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14) TrackingTestUA',
            'ga_client_id' => '1234567890.1731456000',
            'ga_session_id' => '1731456000',
            'fbp' => 'fb.1.1731456000000.987654321',
            'fbc' => 'fb.1.1731456000000.IwAR-test',
        ],
        'consent' => ['analytics' => true, 'ads' => true, 'mode' => 'opt_in', 'policy_version' => '2026-11'],
    ], $overrides);
}

/** @param  array<string, mixed>  $body */
function postTrackingEvent(array $body): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->token)->postJson('/api/v1/storefront/tracking/events', $body);
}

it('returns only public ids and flags from the config endpoint — never a secret', function (): void {
    Setting::put(TrackingSettings::GA4_ENABLED, true, SettingGroup::Tracking);
    Setting::put(TrackingSettings::GA4_MEASUREMENT_ID, 'G-TEST12345', SettingGroup::Tracking);
    Setting::put(TrackingSettings::GA4_API_SECRET, 'ga4-super-secret-value-xyz', SettingGroup::Tracking, true);
    Setting::put(TrackingSettings::META_ENABLED, true, SettingGroup::Tracking);
    Setting::put(TrackingSettings::META_PIXEL_ID, '123456789012345', SettingGroup::Tracking);
    Setting::put(TrackingSettings::META_ACCESS_TOKEN, 'EAAB-meta-access-token-secret', SettingGroup::Tracking, true);
    Setting::put(TrackingSettings::META_TEST_EVENT_CODE, 'TEST98765', SettingGroup::Tracking);
    Setting::put(TrackingSettings::PURCHASE_TRIGGER, 'order_delivered', SettingGroup::Tracking);

    $response = $this->withToken($this->token)->getJson('/api/v1/storefront/tracking/config')->assertOk();

    expect($response->json())->toBe([
        'ga4_measurement_id' => 'G-TEST12345',
        'meta_pixel_id' => '123456789012345',
        'gtm_container_id' => null,
        'consent_mode' => 'opt_in',
        'consent_policy_version' => '2026-11',
        'purchase_trigger' => 'order_delivered',
        'enabled' => ['ga4' => true, 'meta' => true],
    ]);
    expect($response->getContent())->not->toContain('ga4-super-secret-value-xyz')
        ->not->toContain('EAAB-meta-access-token-secret')
        ->not->toContain('TEST98765')
        ->not->toContain('secret')->not->toContain('token');

    // The secret is encrypted at rest (rule 20).
    $raw = DB::table('settings')->where('key', TrackingSettings::GA4_API_SECRET)->value('value');
    expect((string) $raw)->not->toContain('ga4-super-secret-value-xyz');
});

it('hides an id whose destination is disabled', function (): void {
    Setting::put(TrackingSettings::META_PIXEL_ID, '123456789012345', SettingGroup::Tracking);

    $this->withToken($this->token)->getJson('/api/v1/storefront/tracking/config')->assertOk()
        ->assertJsonPath('meta_pixel_id', null)
        ->assertJsonPath('enabled.meta', false);
});

it('needs the storefront token', function (): void {
    $this->getJson('/api/v1/storefront/tracking/config')->assertUnauthorized();
    $this->postJson('/api/v1/storefront/tracking/events', trackingEventBody())->assertUnauthorized();
});

it('accepts a browser event with 202, records it and relays it to Meta only (GA4 is the browser\'s)', function (): void {
    $fakes = FakeTrackingDestination::install();
    $body = trackingEventBody();

    postTrackingEvent($body)->assertStatus(202)->assertExactJson(['event_id' => $body['event_id']]);

    $row = TrackingEvent::query()->sole();
    expect($row->event_name->value)->toBe('add_to_cart')
        ->and($row->source->value)->toBe('browser')
        ->and($row->value?->toDecimal())->toBe('1250.00')
        ->and($row->currency)->toBe('BDT')
        ->and($row->session_id)->toBe('1731456000')
        ->and($row->meta_status)->toBe(DispatchStatus::Sent)
        ->and($row->meta_attempts)->toBe(1)
        ->and($row->ga4_status)->toBe(DispatchStatus::NotApplicable)
        ->and($row->dispatched_at)->not->toBeNull();
    expect($fakes['ga4']->sent)->toBe([])
        ->and($fakes['meta']->sent)->toHaveCount(1);
    $sent = $fakes['meta']->sent[0];
    // The driver got the shopper's real context and the full URL…
    expect($sent->eventId)->toBe($body['event_id'])
        ->and($sent->clientIp)->toBe('103.4.145.27')
        ->and($sent->fbp)->toBe('fb.1.1731456000000.987654321')
        ->and($sent->eventSourceUrl)->toContain('utm_source=fb');
    // …but the stored row holds none of it: no IP, no UA, no click id, no query string.
    $stored = json_encode([$row->payload, $row->meta_response, $row->ga4_response]);
    expect($stored)->not->toContain('103.4.145.27')
        ->not->toContain('TrackingTestUA')
        ->not->toContain('987654321')
        ->not->toContain('IwAR-test')
        ->not->toContain('rahela')
        ->and($row->payload['event_source_url'])->toBe('https://toymartbd.test/products/lego-city-bus')
        ->and($row->payload['sent']['meta']['user_data']['client_ip_address'])->toBe('[redacted]');
});

it('is idempotent on event_id + event_name: a repeat is accepted and never sent twice', function (): void {
    $fakes = FakeTrackingDestination::install();
    $body = trackingEventBody();

    postTrackingEvent($body)->assertStatus(202);
    postTrackingEvent($body)->assertStatus(202)->assertExactJson(['event_id' => $body['event_id']]);
    // Upper-case UUID is the same event.
    postTrackingEvent(['event_id' => strtoupper($body['event_id'])] + $body)->assertStatus(202);

    expect(TrackingEvent::query()->count())->toBe(1)
        ->and($fakes['meta']->sent)->toHaveCount(1);

    // Same id, different name = a different event (Meta dedups on the pair).
    postTrackingEvent(['event_name' => 'begin_checkout'] + $body)->assertStatus(202);
    expect(TrackingEvent::query()->count())->toBe(2);
});

it('records a refused consent as skipped_consent and sends nothing', function (): void {
    $fakes = FakeTrackingDestination::install();

    postTrackingEvent(trackingEventBody(['consent' => ['analytics' => false, 'ads' => false]]))->assertStatus(202);

    $row = TrackingEvent::query()->sole();
    expect($row->meta_status)->toBe(DispatchStatus::SkippedConsent)
        ->and($row->meta_attempts)->toBe(0)
        ->and($row->ga4_status)->toBe(DispatchStatus::NotApplicable)
        ->and($row->consent_state)->toMatchArray(['analytics' => false, 'ads' => false, 'mode' => 'opt_in'])
        ->and($fakes['meta']->sent)->toBe([])
        ->and($fakes['ga4']->sent)->toBe([]);
});

it('records an unconfigured destination as skipped_disabled and sends nothing', function (): void {
    $fakes = FakeTrackingDestination::install(configured: false);

    postTrackingEvent(trackingEventBody())->assertStatus(202);

    expect(TrackingEvent::query()->sole()->meta_status)->toBe(DispatchStatus::SkippedDisabled)
        ->and($fakes['meta']->sent)->toBe([]);
});

it('sends nothing while server-side tracking is switched off', function (): void {
    $fakes = FakeTrackingDestination::install();
    Setting::put(TrackingSettings::SERVER_SIDE_ENABLED, false, SettingGroup::Tracking);

    postTrackingEvent(trackingEventBody())->assertStatus(202);

    expect(TrackingEvent::query()->sole()->meta_status)->toBe(DispatchStatus::SkippedDisabled)
        ->and($fakes['meta']->sent)->toBe([]);
});

it('rejects an event name outside the allowlist with 422', function (string $name): void {
    FakeTrackingDestination::install();

    postTrackingEvent(trackingEventBody(['event_name' => $name]))
        ->assertStatus(422)
        ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'validation-error'));

    expect(TrackingEvent::query()->count())->toBe(0);
})->with(['purchase', 'refund', 'page_view', 'login', 'Purchase']);

it('validates the body', function (array $override, string $field): void {
    postTrackingEvent(trackingEventBody($override))->assertStatus(422)
        ->assertJsonPath('errors', fn (array $errors): bool => in_array($field, array_column($errors, 'field'), true));
})->with([
    'not a uuid' => [['event_id' => 'abc'], 'event_id'],
    'float value' => [['value' => '12.345'], 'value'],
    'foreign currency' => [['currency' => 'USD'], 'currency'],
    'bad ip' => [['client' => ['ip' => 'not-an-ip']], 'client.ip'],
    'consent mode' => [['consent' => ['mode' => 'implied']], 'consent.mode'],
    'unknown client key' => [['client' => ['email' => 'x@example.com']], 'client'],
    'javascript url' => [['event_source_url' => 'javascript:alert(1)'], 'event_source_url'],
]);

it('rate-limits per shopper IP', function (): void {
    config(['tracking.ingest_per_minute' => 3]);
    FakeTrackingDestination::install();

    foreach (range(1, 3) as $i) {
        postTrackingEvent(trackingEventBody())->assertStatus(202);
    }
    postTrackingEvent(trackingEventBody())->assertStatus(429);
    // Another shopper behind the same storefront server is not affected.
    postTrackingEvent(trackingEventBody(['client' => ['ip' => '103.4.145.99']]))->assertStatus(202);
});
