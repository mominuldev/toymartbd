<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Services\MessagingSettings;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Tracking\Enums\DispatchStatus;
use App\Domain\Tracking\Models\TrackingEvent;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CourierFixtures;
use Tests\Support\RoleMatrix;
use Tests\Support\TrackingFixtures;

/*
 * Admin → Integrations hub (Figma B · 37): every status is derived from stored state, and
 * no credential ever appears in the response.
 */

const HUB_URL = '/api/v1/admin/integrations';
const HUB_REVE_KEY = 'reve-api-key-SECRET-7788';
const HUB_REVE_SECRET = 'reve-secret-SECRET-9911';
const HUB_STEADFAST_KEY = 'steadfast-api-key-SECRET-1234';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'notifications.sms.driver' => 'log',
        'notifications.whatsapp.enabled' => false,
        'notifications.sms.drivers.reve' => ['base_url' => null, 'api_key' => null, 'secret_key' => null, 'sender_id' => null, 'timeout' => 5],
        'tracking.server_side_enabled' => false,
        'tracking.ga4.enabled' => false,
        'tracking.ga4.measurement_id' => null,
        'tracking.ga4.api_secret' => null,
        'tracking.meta.enabled' => false,
        'tracking.meta.pixel_id' => null,
        'tracking.meta.access_token' => null,
        'tracking.meta.test_event_code' => null,
        'tracking.gtm_container_id' => null,
        'couriers.pathao.sandbox' => true,
        'couriers.pathao.client_id' => null,
    ]);
});

function hubOwner(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array<string, mixed> */
function hubItem(TestResponse $response, string $key): array
{
    $item = collect($response->json('data'))->firstWhere('key', $key);
    expect($item)->not->toBeNull("no integration `{$key}`");

    return $item;
}

function hubSteadfastWithCredentials(array $attributes = []): void
{
    $p = CourierFixtures::provider('steadfast', $attributes);
    $p->credentials = ['api_key' => HUB_STEADFAST_KEY, 'secret_key' => 'steadfast-secret-SECRET-5678'];
    $p->save();
}

it('lists every service by category, with not-built services as not_available', function (): void {
    hubOwner();
    CourierFixtures::provider('steadfast');

    $response = $this->getJson(HUB_URL)->assertOk();

    $keys = collect($response->json('data'))->pluck('key')->all();
    expect($keys)->toBe(['steadfast', 'redx', 'cod', 'sslcommerz', 'bkash', 'nagad', 'sms', 'whatsapp', 'messenger', 'meta_capi', 'ga4', 'gtm', 'search_console']);
    expect(collect($response->json('data'))->pluck('category')->unique()->values()->all())->toBe(['couriers', 'payments', 'messaging', 'marketing']);

    foreach (['redx', 'bkash', 'nagad', 'whatsapp', 'messenger', 'search_console'] as $key) {
        expect(hubItem($response, $key))->toMatchArray(['status' => 'not_available', 'notes' => ['no_driver'], 'settings_path' => null, 'enabled' => null]);
    }
    // COD is first-class and always on (rule 33).
    expect(hubItem($response, 'cod'))->toMatchArray(['status' => 'connected_live', 'notes' => ['cod_always_on']]);
});

it('derives courier status from credentials, verification and sandbox mode', function (): void {
    hubOwner();
    CourierFixtures::provider('pathao', ['is_default' => false, 'position' => 1, 'is_active' => false]);
    CourierFixtures::provider('steadfast');

    // No credentials anywhere → not connected.
    $response = $this->getJson(HUB_URL)->assertOk();
    expect(hubItem($response, 'steadfast'))->toMatchArray([
        'status' => 'not_connected',
        'notes' => ['no_sandbox', 'no_credentials', 'unverified_booking_blocked'],
        'settings_path' => '/integrations/couriers',
        'enabled' => true,
        'can_toggle' => true,
    ]);
    expect(hubItem($response, 'pathao'))->toMatchArray(['status' => 'not_connected', 'enabled' => false]);

    // Steadfast credentials saved but unverified (no sandbox, rule 33a) → test mode, bookings blocked.
    hubSteadfastWithCredentials();
    $response = $this->getJson(HUB_URL)->assertOk();
    expect(hubItem($response, 'steadfast'))->toMatchArray([
        'status' => 'test_mode',
        'notes' => ['no_sandbox', 'unverified_booking_blocked', 'webhook_secret_missing'],
    ]);

    // Pathao with full credentials, verified, but still on the sandbox host → test mode.
    $pathao = CourierFixtures::provider('pathao', ['is_default' => false, 'verified' => true]);
    $pathao->credentials = ['client_id' => 'cid', 'client_secret' => 'csecret-1234567890', 'username' => 'm@example.test', 'password' => 'pw-1234567890', 'store_id' => '42'];
    $pathao->webhook_secret = 'whsec-1234567890';
    $pathao->save();
    expect(hubItem($this->getJson(HUB_URL), 'pathao'))->toMatchArray(['status' => 'test_mode', 'notes' => ['sandbox']]);

    // Verified, row says production and env allows live → connected · live.
    config(['couriers.pathao.sandbox' => false]);
    $pathao->settings = ['environment' => 'production'];
    $pathao->save();
    expect(hubItem($this->getJson(HUB_URL), 'pathao'))->toMatchArray(['status' => 'connected_live', 'notes' => []]);

    // An open circuit breaker beats everything → needs attention.
    $breaker = app(CourierCircuitBreaker::class);
    for ($i = 0; $i < 10; $i++) {
        $breaker->recordFailure('pathao');
    }
    expect(hubItem($this->getJson(HUB_URL), 'pathao'))->toMatchArray(['status' => 'needs_attention', 'attention' => 'courier_unreachable']);
});

it('counts only booked shipments for a courier', function (): void {
    hubOwner();
    hubSteadfastWithCredentials();
    $provider = CourierFixtures::provider('steadfast');
    $order = App\Domain\Ordering\Models\Order::factory()->create();
    foreach (['C-1', 'C-2', null] as $i => $consignment) {
        App\Domain\Fulfilment\Models\Shipment::query()->create([
            'order_id' => $order->id, 'provider_id' => $provider->id, 'number' => 'SHP-26-00000'.$i,
            'consignment_id' => $consignment,
            'status' => $consignment === null ? App\Domain\Fulfilment\Enums\ShipmentStatus::Failed : App\Domain\Fulfilment\Enums\ShipmentStatus::Booked,
            'weight_grams' => 500, 'item_count' => 1, 'cod_amount' => '500.00', 'declared_value' => '500.00',
        ]);
    }

    $item = hubItem($this->getJson(HUB_URL), 'steadfast');
    expect($item['bookings_count'])->toBe(2)->and($item['notes'])->toContain('bookings');
});

it('only offers the courier switch to someone who may change rates', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    CourierFixtures::provider('steadfast');
    // manager holds manage_integrations; check against the role matrix rather than assume.
    foreach ([Role::SuperAdmin, Role::Manager] as $role) {
        if (! RoleMatrix::allows($role, Permission::SettingsManageIntegrations)) {
            continue;
        }
        $user = User::factory()->withRole($role)->create()->refresh();
        $this->actingAs($user, 'web');
        expect(hubItem($this->getJson(HUB_URL)->assertOk(), 'steadfast')['can_toggle'])
            ->toBe(RoleMatrix::allows($role, Permission::ShipmentsManageRates));
    }
});

it('derives payment status from the gateway switchboard', function (): void {
    hubOwner();
    config(['payments.sslcommerz.store_id' => null, 'payments.sslcommerz.store_password' => null]);
    PaymentProvider::query()->where('code', 'sslcommerz')->update(['credentials' => null]);

    $item = hubItem($this->getJson(HUB_URL)->assertOk(), 'sslcommerz');
    expect($item['status'])->toBe('not_connected')
        ->and($item['notes'])->toContain('unverified_payment_blocked')
        ->and($item['notes'])->toContain('switched_off')
        ->and($item['enabled'])->toBeFalse()
        ->and($item['settings_path'])->toBeNull();
});

it('derives SMS status from the messaging settings', function (): void {
    hubOwner();

    // log driver → not connected.
    expect(hubItem($this->getJson(HUB_URL), 'sms'))->toMatchArray(['status' => 'not_connected', 'notes' => ['driver_log'], 'settings_path' => '/integrations/messaging']);

    // REVE selected, credentials missing → needs attention.
    Setting::put(MessagingSettings::SMS_DRIVER, 'reve', SettingGroup::Notification);
    expect(hubItem($this->getJson(HUB_URL), 'sms'))->toMatchArray(['status' => 'needs_attention', 'attention' => 'sms_credentials_missing', 'notes' => ['driver_reve']]);

    // REVE fully configured → connected · live.
    Setting::put(MessagingSettings::REVE_BASE_URL, 'https://smpp.example.test:7790', SettingGroup::Notification);
    Setting::put(MessagingSettings::REVE_SENDER_ID, '8809612345678', SettingGroup::Notification);
    Setting::put(MessagingSettings::REVE_API_KEY, HUB_REVE_KEY, SettingGroup::Notification, encrypted: true);
    Setting::put(MessagingSettings::REVE_SECRET_KEY, HUB_REVE_SECRET, SettingGroup::Notification, encrypted: true);
    $response = $this->getJson(HUB_URL);
    expect(hubItem($response, 'sms'))->toMatchArray(['status' => 'connected_live', 'attention' => null]);

    // The last SMS in 24 h failed → needs attention.
    NotificationLog::query()->forceCreate([
        'channel' => 'sms', 'template' => 'order_placed', 'category' => 'order_updates', 'locale' => 'en',
        'status' => 'failed', 'cost' => '0.00', 'attempts' => 1, 'error' => 'provider refused', 'failed_at' => now(),
    ]);
    expect(hubItem($this->getJson(HUB_URL), 'sms'))->toMatchArray(['status' => 'needs_attention', 'attention' => 'sms_last_send_failed']);

    // No credential appears anywhere in the body.
    $body = $this->getJson(HUB_URL)->getContent();
    expect($body)->not->toContain(HUB_REVE_KEY)->not->toContain(HUB_REVE_SECRET)->not->toContain('7788');
});

it('flags WhatsApp switched on without a real driver', function (): void {
    hubOwner();
    Setting::put(MessagingSettings::WHATSAPP_ENABLED, true, SettingGroup::Notification);

    expect(hubItem($this->getJson(HUB_URL), 'whatsapp'))->toMatchArray([
        'status' => 'needs_attention', 'attention' => 'whatsapp_on_without_driver', 'enabled' => true, 'settings_path' => '/integrations/messaging',
    ]);
});

it('derives tracking status, and a recent dispatch error is needs_attention', function (): void {
    hubOwner();

    expect(hubItem($this->getJson(HUB_URL), 'ga4'))->toMatchArray(['status' => 'not_connected', 'notes' => ['switched_off'], 'enabled' => false]);

    TrackingFixtures::configure(withTestCode: true);
    config(['tracking.gtm_container_id' => 'GTM-W7K3PL9']);
    $response = $this->getJson(HUB_URL);
    expect(hubItem($response, 'ga4'))->toMatchArray(['status' => 'connected_live', 'notes' => ['server_and_browser'], 'enabled' => true]);
    expect(hubItem($response, 'meta_capi'))->toMatchArray(['status' => 'test_mode', 'notes' => ['server_and_browser', 'test_event_code']]);
    expect(hubItem($response, 'gtm'))->toMatchArray(['status' => 'connected_live', 'notes' => ['container_set']]);

    // A Meta send failed an hour ago, after the last success → needs attention.
    $event = fn (string $meta, string $when): TrackingEvent => TrackingEvent::query()->create([
        'event_id' => (string) Str::uuid(), 'event_name' => 'purchase', 'source' => 'server',
        'value' => '1250.00', 'currency' => 'BDT', 'payload' => [],
        'consent_state' => ['analytics' => true, 'ads' => true, 'mode' => 'opt_in'],
        'ga4_status' => DispatchStatus::NotApplicable->value, 'meta_status' => $meta,
        'meta_response' => $meta === 'failed' ? ['error' => ['message' => 'Invalid OAuth access token']] : null,
        'occurred_at' => $when, 'dispatched_at' => $when, 'created_at' => $when,
    ]);
    $event(DispatchStatus::Sent->value, now()->subHours(3)->toDateTimeString());
    $event(DispatchStatus::Failed->value, now()->subHour()->toDateTimeString());

    $response = $this->getJson(HUB_URL);
    expect(hubItem($response, 'meta_capi'))->toMatchArray(['status' => 'needs_attention', 'attention' => 'tracking_send_failing']);
    expect(hubItem($response, 'ga4')['status'])->toBe('connected_live');

    // A later success clears it.
    $event(DispatchStatus::Sent->value, now()->toDateTimeString());
    expect(hubItem($this->getJson(HUB_URL), 'meta_capi')['status'])->toBe('test_mode');

    $body = $this->getJson(HUB_URL)->getContent();
    expect($body)->not->toContain(TrackingFixtures::GA4_SECRET)->not->toContain(TrackingFixtures::META_TOKEN);
});

it('never returns a courier credential', function (): void {
    hubOwner();
    hubSteadfastWithCredentials();

    $body = $this->getJson(HUB_URL)->assertOk()->getContent();
    expect($body)->not->toContain(HUB_STEADFAST_KEY)->not->toContain('1234')->not->toContain('SECRET');
});

it('guards GET /integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(HUB_URL));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));
