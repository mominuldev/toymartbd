<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Ordering\Models\Order;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Domain\Tracking\Services\TrackingSettings;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\RoleMatrix;

/*
 * Admin → Integrations → Tracking & pixels and its Diagnostics (PRD K-01..K-03): settings
 * with write-only secrets, the "Send test event" relay, the event log (allowlisted filters,
 * Dhaka dates), one event as sent, and the health summary. Rule 21: every endpoint has a
 * role test asserting 403 for unauthorised roles.
 */

const TRK_URL = '/api/v1/admin/settings/tracking';
const TRK_GA4_SECRET = 'ga4-secret-value-ABCD1234';
const TRK_META_TOKEN = 'EAAB-meta-token-value-WXYZ9876';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'tracking.server_side_enabled' => false,
        'tracking.ga4.enabled' => false,
        'tracking.ga4.measurement_id' => null,
        'tracking.ga4.api_secret' => null,
        'tracking.meta.enabled' => false,
        'tracking.meta.pixel_id' => null,
        'tracking.meta.access_token' => null,
        'tracking.meta.test_event_code' => null,
        'tracking.gtm_container_id' => null,
        'tracking.ga4.base_url' => 'https://ga4.example.test',
        'tracking.meta.base_url' => 'https://graph.example.test',
    ]);
});

/** @return list<string> The fields an RFC 9457 validation problem names. */
function adminTrkErrorFields(Illuminate\Testing\TestResponse $response): array
{
    return collect($response->json('errors'))->pluck('field')->unique()->values()->all();
}

function adminTrkUser(Role $role = Role::SuperAdmin): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array<string, mixed> */
function adminTrkBody(array $overrides = []): array
{
    return [
        'server_side_enabled' => true,
        'ga4_enabled' => true,
        'ga4_measurement_id' => 'G-TMB8X2K4QZ',
        'ga4_api_secret' => TRK_GA4_SECRET,
        'meta_enabled' => true,
        'meta_pixel_id' => '104822917731',
        'meta_access_token' => TRK_META_TOKEN,
        'meta_test_event_code' => null,
        'gtm_container_id' => 'GTM-W7K3PL9',
        'purchase_trigger' => 'order_placed',
        'consent_mode' => 'opt_in',
        'consent_policy_version' => '2026-11',
        ...$overrides,
    ];
}

/** @param array<string, mixed> $attributes */
function adminTrkEvent(array $attributes = []): TrackingEvent
{
    return TrackingEvent::query()->create([
        'event_id' => (string) Str::uuid(),
        'event_name' => 'add_to_cart',
        'source' => 'browser',
        'value' => '1250.00',
        'currency' => 'BDT',
        'payload' => ['items' => [['item_id' => 'TM-00001', 'quantity' => 1]]],
        'consent_state' => ['analytics' => true, 'ads' => true, 'mode' => 'opt_in', 'policy_version' => '2026-11'],
        'ga4_status' => DispatchStatus::NotApplicable->value,
        'meta_status' => DispatchStatus::Sent->value,
        'occurred_at' => now(),
        'dispatched_at' => now(),
        'created_at' => now(),
        ...$attributes,
    ]);
}

// ── Settings ────────────────────────────────────────────────────────────────────────

it('saves settings, never returns a secret, and revalidates tracking-config', function (): void {
    Queue::fake();
    adminTrkUser();

    $response = $this->putJson(TRK_URL, adminTrkBody())->assertOk();

    expect($response->getContent())->not->toContain(TRK_GA4_SECRET)->not->toContain(TRK_META_TOKEN)->not->toContain('ABCD1234');
    $response->assertJson([
        'ga4_enabled' => true,
        'ga4_measurement_id' => 'G-TMB8X2K4QZ',
        'ga4_api_secret_set' => true,
        'ga4_api_secret_hint' => '••••1234',
        'ga4_api_secret_source' => 'settings',
        'meta_access_token_set' => true,
        'meta_access_token_hint' => '••••9876',
        'purchase_trigger' => 'order_placed',
        'consent_mode' => 'opt_in',
    ]);
    Queue::assertPushed(RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['tracking-config']);

    $show = $this->getJson(TRK_URL)->assertOk();
    expect($show->getContent())->not->toContain(TRK_GA4_SECRET)->not->toContain(TRK_META_TOKEN);
});

it('keeps a saved secret when the field is blank, and clears it on request', function (): void {
    Queue::fake();
    adminTrkUser();
    $this->putJson(TRK_URL, adminTrkBody())->assertOk();

    $this->putJson(TRK_URL, adminTrkBody(['ga4_api_secret' => '', 'meta_access_token' => null, 'purchase_trigger' => 'order_delivered']))
        ->assertOk()
        ->assertJson(['ga4_api_secret_set' => true, 'meta_access_token_set' => true, 'purchase_trigger' => 'order_delivered']);
    expect(app(TrackingSettings::class)->ga4ApiSecret())->toBe(TRK_GA4_SECRET);

    $this->putJson(TRK_URL, adminTrkBody(['ga4_enabled' => false, 'ga4_api_secret' => null, 'clear_ga4_api_secret' => true]))
        ->assertOk()
        ->assertJson(['ga4_api_secret_set' => false, 'ga4_enabled' => false]);
});

it('refuses to turn a destination on without its id and secret', function (): void {
    adminTrkUser();

    $response = $this->putJson(TRK_URL, adminTrkBody(['ga4_api_secret' => null, 'meta_pixel_id' => null]))->assertUnprocessable();

    expect(adminTrkErrorFields($response))->toEqualCanonicalizing(['ga4_api_secret', 'meta_pixel_id'])
        ->and($response->json('errors.0.message'))->toBe('Required to turn this destination on.');
});

it('validates id formats, trigger and consent mode', function (): void {
    adminTrkUser();

    $response = $this->putJson(TRK_URL, adminTrkBody([
        'ga4_measurement_id' => 'UA-12345-1',
        'meta_pixel_id' => 'abc',
        'gtm_container_id' => 'W7K3PL9',
        'purchase_trigger' => 'order_shipped',
        'consent_mode' => 'implied',
    ]))->assertUnprocessable();
    expect(adminTrkErrorFields($response))->toEqualCanonicalizing(['ga4_measurement_id', 'meta_pixel_id', 'gtm_container_id', 'purchase_trigger', 'consent_mode']);

    // Lower-case ids pasted from a console are normalised, not refused.
    Queue::fake();
    $this->putJson(TRK_URL, adminTrkBody(['ga4_measurement_id' => ' g-tmb8x2k4qz ', 'gtm_container_id' => 'gtm-w7k3pl9']))
        ->assertOk()
        ->assertJson(['ga4_measurement_id' => 'G-TMB8X2K4QZ', 'gtm_container_id' => 'GTM-W7K3PL9']);
});

// ── Send test event ─────────────────────────────────────────────────────────────────

it('relays GA4 validation messages from the debug endpoint, without the secret', function (): void {
    Queue::fake();
    adminTrkUser();
    $this->putJson(TRK_URL, adminTrkBody(['meta_enabled' => false]))->assertOk();
    Http::fake(['https://ga4.example.test/debug/mp/collect*' => Http::response([
        'validationMessages' => [['fieldPath' => 'events[0].params.items', 'description' => 'Item id is required.', 'validationCode' => 'VALUE_REQUIRED']],
    ])]);

    $response = $this->postJson(TRK_URL.'/test', ['destination' => 'ga4'])->assertOk();

    $response->assertJson(['destination' => 'ga4', 'valid' => false, 'status' => 'failed', 'http_status' => 200])
        ->assertJsonPath('messages.0', 'VALUE_REQUIRED events[0].params.items: Item id is required.');
    expect($response->getContent())->not->toContain(TRK_GA4_SECRET);
    $response->assertJsonPath('reason', null);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/debug/mp/collect'));
});

it('reports an accepted GA4 test event as valid', function (): void {
    Queue::fake();
    adminTrkUser();
    $this->putJson(TRK_URL, adminTrkBody(['meta_enabled' => false]))->assertOk();
    Http::fake(['https://ga4.example.test/debug/mp/collect*' => Http::response(['validationMessages' => []])]);

    $this->postJson(TRK_URL.'/test', ['destination' => 'ga4'])->assertOk()->assertJson(['valid' => true, 'reason' => null, 'messages' => []]);
});

it('gives a reason code, not English text, when the destination is not configured', function (): void {
    adminTrkUser();
    Http::fake();

    $this->postJson(TRK_URL.'/test', ['destination' => 'ga4'])->assertOk()
        ->assertJson(['valid' => false, 'status' => 'skipped_disabled', 'reason' => 'not_configured', 'messages' => []]);
    Http::assertNothingSent();
});

it('refuses a Meta test event without a test event code, sending nothing', function (): void {
    Queue::fake();
    adminTrkUser();
    $this->putJson(TRK_URL, adminTrkBody(['ga4_enabled' => false]))->assertOk();
    Http::fake();

    $this->postJson(TRK_URL.'/test', ['destination' => 'meta'])->assertOk()
        ->assertJson(['valid' => false, 'reason' => 'meta_test_code_missing', 'messages' => []]);
    Http::assertNothingSent();
});

it('rejects an unknown test destination', function (): void {
    adminTrkUser();
    $this->postJson(TRK_URL.'/test', ['destination' => 'tiktok'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'destination');
});

// ── Event log ───────────────────────────────────────────────────────────────────────

it('lists events newest first with per-destination status', function (): void {
    adminTrkUser();
    $old = adminTrkEvent(['event_name' => 'view_item']);
    $new = adminTrkEvent(['event_name' => 'purchase', 'source' => 'server', 'ga4_status' => 'failed', 'ga4_response' => ['error' => 'GA4: HTTP 400.', 'http_status' => 400]]);

    $this->getJson('/api/v1/admin/tracking/events')
        ->assertOk()
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.0.ga4.status', 'failed')
        ->assertJsonPath('data.0.ga4.error', 'GA4: HTTP 400.')
        ->assertJsonPath('data.0.value', '1250.00')
        ->assertJsonPath('data.1.id', $old->id)
        ->assertJsonPath('data.1.ga4.status', 'not_applicable');
});

it('filters by event, source, status and order number', function (): void {
    adminTrkUser();
    $order = Order::factory()->create();
    adminTrkEvent(['event_name' => 'view_item']);
    $match = adminTrkEvent(['event_name' => 'purchase', 'source' => 'server', 'order_id' => $order->id, 'meta_status' => 'skipped_consent']);

    $query = http_build_query(['filter' => ['event_name' => 'purchase', 'source' => 'server', 'meta_status' => 'skipped_consent', 'order_number' => strtolower((string) $order->number)]]);
    $this->getJson('/api/v1/admin/tracking/events?'.$query)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id)
        ->assertJsonPath('data.0.order_number', $order->number);
});

it('filters by Dhaka calendar date, not UTC date (rule 11)', function (): void {
    adminTrkUser();
    // 2026-11-12 19:30 UTC is 2026-11-13 01:30 in Dhaka.
    $lateUtc = adminTrkEvent(['created_at' => CarbonImmutable::parse('2026-11-12 19:30:00', 'UTC')]);
    adminTrkEvent(['created_at' => CarbonImmutable::parse('2026-11-12 17:30:00', 'UTC')]); // 23:30 Dhaka, the 12th

    $this->getJson('/api/v1/admin/tracking/events?filter[date_from]=2026-11-13&filter[date_to]=2026-11-13')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $lateUtc->id);
});

it('answers 400 to an unlisted filter, a sort, or a bad filter value', function (string $query): void {
    adminTrkUser();
    $this->getJson('/api/v1/admin/tracking/events?'.$query)->assertStatus(400);
})->with([
    'unlisted filter' => ['filter[customer_id]=1'],
    'sort' => ['sort=-created_at'],
    'bad status' => ['filter[ga4_status]=delivered'],
    'bad event' => ['filter[event_name]=signup'],
    'bad date' => ['filter[date_from]=13/11/2026'],
    'impossible date' => ['filter[date_to]=2026-02-30'],
]);

it('shows one event as sent, with any secret key stripped', function (): void {
    adminTrkUser();
    $event = adminTrkEvent([
        'payload' => [
            'items' => [],
            'sent' => ['meta' => ['data' => [['event_name' => 'AddToCart', 'user_data' => ['ph' => [hash('sha256', '8801712345678')]]]], 'access_token' => 'leaked-token']],
        ],
        'meta_response' => ['http_status' => 200, 'excerpt' => '{"events_received":1}', 'error' => null],
    ]);

    $response = $this->getJson('/api/v1/admin/tracking/events/'.$event->id)->assertOk();

    $response->assertJsonPath('payload.sent.meta.data.0.event_name', 'AddToCart')
        ->assertJsonPath('meta_response.http_status', 200)
        ->assertJsonPath('consent_policy_version', '2026-11');
    expect($response->getContent())->not->toContain('leaked-token')->not->toContain('access_token');
});

// ── Summary ─────────────────────────────────────────────────────────────────────────

it('summarises health per destination: counts, consent-skip and pairing rates, last error', function (): void {
    adminTrkUser();
    adminTrkEvent(); // browser, meta sent
    adminTrkEvent(); // browser, meta sent
    adminTrkEvent(['meta_status' => 'failed', 'meta_response' => ['error' => 'Meta: (#100) Invalid parameter']]);
    adminTrkEvent(['meta_status' => 'skipped_consent']);
    adminTrkEvent(['meta_status' => 'sent', 'created_at' => now()->subDays(3)]);
    adminTrkEvent(['meta_status' => 'sent', 'created_at' => now()->subDays(10)]); // outside 7 d
    adminTrkEvent(['source' => 'server', 'event_name' => 'purchase', 'ga4_status' => 'sent', 'meta_status' => 'sent']);

    $response = $this->getJson('/api/v1/admin/tracking/summary')->assertOk();
    $meta = collect($response->json('destinations'))->firstWhere('destination', 'meta');
    $ga4 = collect($response->json('destinations'))->firstWhere('destination', 'ga4');

    expect($meta['window_24h'])->toMatchArray(['sent' => 3, 'failed' => 1, 'skipped_consent' => 1, 'total' => 5])
        ->and($meta['window_7d'])->toMatchArray(['sent' => 4, 'total' => 6])
        ->and($meta['consent_skip_rate'])->toBe('16.7')
        // Browser rows in 7 d: 5; sent: 3 → 60.0 %.
        ->and($meta['pairing_rate'])->toBe('60.0')
        ->and($meta['last_error'])->toBe('Meta: (#100) Invalid parameter')
        ->and($meta['last_success_at'])->not->toBeNull()
        ->and($ga4['window_24h'])->toMatchArray(['sent' => 1, 'total' => 1])
        ->and($ga4['pairing_rate'])->toBeNull();
});

it('summarises an empty log without dividing by zero', function (): void {
    adminTrkUser();
    $this->getJson('/api/v1/admin/tracking/summary')
        ->assertOk()
        ->assertJsonPath('destinations.0.consent_skip_rate', null)
        ->assertJsonPath('destinations.1.pairing_rate', null)
        ->assertJsonPath('destinations.0.last_success_at', null);
});

// ── Rule 21: role tests ─────────────────────────────────────────────────────────────

it('guards GET /settings/tracking with settings.manage_integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(TRK_URL));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('guards PUT /settings/tracking with settings.manage_integrations', function (Role $role, bool $allowed): void {
    Queue::fake();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(TRK_URL, adminTrkBody(['ga4_enabled' => false, 'meta_enabled' => false])));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('guards POST /settings/tracking/test with settings.manage_integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(TRK_URL.'/test', ['destination' => 'ga4']));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('guards GET /tracking/events with settings.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/tracking/events'));
})->with(RoleMatrix::dataset(Permission::SettingsView));

it('guards GET /tracking/events/{id} with settings.view', function (Role $role, bool $allowed): void {
    $event = adminTrkEvent();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/tracking/events/'.$event->id));
})->with(RoleMatrix::dataset(Permission::SettingsView));

it('guards GET /tracking/summary with settings.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/tracking/summary'));
})->with(RoleMatrix::dataset(Permission::SettingsView));

it('keeps diagnostics away from the viewer and accountant roles (reports.view is not enough)', function (): void {
    expect(RoleMatrix::allows(Role::Viewer, Permission::SettingsView))->toBeFalse()
        ->and(RoleMatrix::allows(Role::Accountant, Permission::SettingsView))->toBeFalse()
        ->and(RoleMatrix::allows(Role::Manager, Permission::SettingsView))->toBeTrue();
});
