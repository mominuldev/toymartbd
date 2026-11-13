<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Notification\Actions\QueueOrderNotification;
use App\Domain\Notification\Drivers\ReveSmsGateway;
use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Notification\Services\MessagingSettings;
use App\Domain\Notification\Services\SmsDrivers;
use App\Domain\Notification\Support\SmsTariff;
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\RoleMatrix;

/*
 * Admin → Integrations → Messaging (owner request 2026-11-13): SMS credentials entered in
 * the admin, encrypted in `settings`, env as the fallback. Secrets never come back out —
 * not in the API, not in a log, not in the audit trail.
 */

const MSG_URL = '/api/v1/admin/settings/messaging';
const ENV_KEY = 'env-api-key-0000-ENV1';
const ENV_SECRET = 'env-secret-key-000-ENV2';
const DB_KEY = 'db-api-key-abcdef-9876';
const DB_SECRET = 'db-secret-key-ghijk-5432';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'notifications.sms.driver' => 'log',
        'notifications.sms.cost_per_segment' => '0.64',
        'notifications.whatsapp.enabled' => false,
        'notifications.sms.drivers.reve' => [
            'base_url' => 'https://env.example.test:7790',
            'api_key' => ENV_KEY,
            'secret_key' => ENV_SECRET,
            'sender_id' => 'EnvSender',
            'timeout' => 5,
        ],
    ]);
});

function messagingAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array<string, mixed> */
function reveSettings(array $overrides = []): array
{
    return [
        'sms_driver' => 'reve',
        'reve_base_url' => 'https://smpp.example.test:7790',
        'reve_sender_id' => '8809612345678',
        'sms_cost_per_segment' => '0.55',
        'whatsapp_enabled' => false,
        'reve_api_key' => DB_KEY,
        'reve_secret_key' => DB_SECRET,
        ...$overrides,
    ];
}

function acceptAll(string $id = '901'): void
{
    Http::fake(['*' => Http::response(['Status' => '0', 'Text' => 'ACCEPTD', 'Message_ID' => $id])]);
}

it('falls back to env when nothing is saved, and shows secrets only as set + last-4 hint', function (): void {
    messagingAdmin();

    $response = $this->getJson(MSG_URL)->assertOk();

    $response->assertJson([
        'sms_driver' => 'log',
        'reve_base_url' => 'https://env.example.test:7790',
        'reve_sender_id' => 'EnvSender',
        'sms_cost_per_segment' => '0.64',
        'whatsapp_enabled' => false,
        'reve_api_key_set' => true,
        'reve_api_key_hint' => '••••ENV1',
        'reve_api_key_source' => 'env',
        'reve_secret_key_set' => true,
        'reve_secret_key_source' => 'env',
        'sources' => ['sms_driver' => 'env', 'reve_base_url' => 'env'],
    ]);
    expect($response->getContent())->not->toContain(ENV_KEY)->not->toContain(ENV_SECRET);
});

it('saves to settings, encrypts secrets at rest, and never returns them', function (): void {
    $user = messagingAdmin();

    $response = $this->putJson(MSG_URL, reveSettings())->assertOk()
        ->assertJson([
            'sms_driver' => 'reve',
            'reve_base_url' => 'https://smpp.example.test:7790',
            'reve_sender_id' => '8809612345678',
            'sms_cost_per_segment' => '0.55',
            'reve_api_key_set' => true,
            'reve_api_key_hint' => '••••9876',
            'reve_api_key_source' => 'settings',
            'reve_secret_key_hint' => '••••5432',
            'sources' => ['sms_driver' => 'settings', 'sms_cost_per_segment' => 'settings'],
        ]);
    expect($response->getContent())->not->toContain(DB_KEY)->not->toContain(DB_SECRET);
    expect($this->getJson(MSG_URL)->getContent())->not->toContain(DB_KEY)->not->toContain(DB_SECRET);

    // Raw column: an encrypted payload, not the plaintext.
    $raw = DB::table('settings')->where('key', MessagingSettings::REVE_API_KEY)->first();
    expect($raw->is_encrypted)->toBeTruthy()
        ->and($raw->value)->not->toContain(DB_KEY)
        ->and($raw->updated_by)->toBe($user->id)
        ->and(Setting::query()->find(MessagingSettings::REVE_API_KEY)?->value)->toBe(DB_KEY)
        ->and(Setting::query()->find(MessagingSettings::REVE_SECRET_KEY)?->group)->toBe(SettingGroup::Notification);
    expect(DB::table('settings')->where('key', MessagingSettings::REVE_SECRET_KEY)->value('value'))->not->toContain(DB_SECRET);
});

it('uses saved settings over env for the driver, the credentials and the tariff', function (): void {
    messagingAdmin();
    $this->putJson(MSG_URL, reveSettings())->assertOk();
    acceptAll();

    expect(app(SmsDrivers::class)->name())->toBe('reve')
        ->and(app(SmsDrivers::class)->driver())->toBeInstanceOf(ReveSmsGateway::class)
        ->and(app(SmsTariff::class)->perSegment()->toDecimal())->toBe('0.55');

    $result = app(ReveSmsGateway::class)->send('+8801712345678', 'hello', 'test');

    expect($result->accepted)->toBeTrue()->and($result->cost->toDecimal())->toBe('0.55');
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://smpp.example.test:7790/sendtext'
        && $r['apikey'] === DB_KEY && $r['secretkey'] === DB_SECRET && $r['callerID'] === '8809612345678');
});

it('keeps a saved secret when the field is blank, and clearing falls back to env', function (): void {
    messagingAdmin();
    $this->putJson(MSG_URL, reveSettings())->assertOk();

    $this->putJson(MSG_URL, reveSettings(['reve_api_key' => '', 'reve_secret_key' => null, 'reve_sender_id' => '01712345679']))
        ->assertOk()
        ->assertJsonPath('reve_api_key_source', 'settings')
        ->assertJsonPath('reve_api_key_hint', '••••9876')
        ->assertJsonPath('reve_sender_id', '01712345679');
    expect(Setting::query()->find(MessagingSettings::REVE_API_KEY)?->value)->toBe(DB_KEY)
        ->and(Setting::query()->find(MessagingSettings::REVE_SECRET_KEY)?->value)->toBe(DB_SECRET);

    $this->putJson(MSG_URL, reveSettings(['reve_api_key' => null, 'reve_secret_key' => null, 'clear_reve_api_key' => true]))
        ->assertOk()
        ->assertJsonPath('reve_api_key_source', 'env')
        ->assertJsonPath('reve_api_key_hint', '••••ENV1')
        ->assertJsonPath('reve_secret_key_source', 'settings');
    expect(Setting::query()->find(MessagingSettings::REVE_API_KEY))->toBeNull();
});

it('applies a change to the very next send on the same, already-resolved driver (no restart)', function (): void {
    messagingAdmin();
    $this->putJson(MSG_URL, reveSettings())->assertOk();
    acceptAll();
    // A long-running queue worker holds its resolved objects across jobs.
    $gateway = app(ReveSmsGateway::class);
    $drivers = app(SmsDrivers::class);

    $gateway->send('+8801712345678', 'one', 'test');
    Setting::put(MessagingSettings::REVE_API_KEY, 'rotated-key-zzzz-1111', SettingGroup::Notification, true);
    Setting::put(MessagingSettings::SMS_DRIVER, 'log', SettingGroup::Notification);
    $gateway->send('+8801712345678', 'two', 'test');

    $keys = Http::recorded()->map(fn (array $pair): string => $pair[0]['apikey'])->all();
    expect($keys)->toBe([DB_KEY, 'rotated-key-zzzz-1111'])
        ->and($drivers->name())->toBe('log');
});

it('records who changed what in the audit trail, never a secret value', function (): void {
    $user = messagingAdmin();

    $this->putJson(MSG_URL, reveSettings())->assertOk();
    $this->putJson(MSG_URL, reveSettings(['reve_api_key' => null, 'reve_secret_key' => null]))->assertOk(); // no change → no row

    $audit = AuditSensitive::query()->sole();
    expect($audit->action)->toBe(AuditAction::CredentialChanged)
        ->and($audit->user_id)->toBe($user->id)
        ->and($audit->after)->toMatchArray([
            'scope' => 'messaging',
            'sms_driver' => 'reve',
            'reve_api_key' => 'changed',
            'reve_secret_key' => 'changed',
            'sms_cost_per_segment' => '0.55',
        ]);
    $json = json_encode([$audit->before, $audit->after]);
    expect($json)->not->toContain(DB_KEY)->not->toContain(DB_SECRET)->not->toContain('9876');

    $this->putJson(MSG_URL, reveSettings(['reve_api_key' => null, 'reve_secret_key' => null, 'clear_reve_secret_key' => true]))->assertOk();
    expect(AuditSensitive::query()->latest('id')->first()?->after)->toBe(['scope' => 'messaging', 'reve_secret_key' => 'cleared']);
});

it('never writes a secret to the log while saving or test-sending', function (): void {
    messagingAdmin();
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = json_encode([$message->message, $message->context]);
    });
    acceptAll();

    $this->putJson(MSG_URL, reveSettings())->assertOk();
    $this->postJson(MSG_URL.'/test', ['phone' => '01712345678', 'locale' => 'en'])->assertOk();
    $this->putJson(MSG_URL, reveSettings(['sms_cost_per_segment' => 'abc']))->assertUnprocessable();

    $all = implode("\n", $logged);
    expect($all)->not->toContain(DB_KEY)->not->toContain(DB_SECRET)->not->toContain('01712345678');
});

it('validates the form', function (array $overrides, string $field): void {
    messagingAdmin();

    $this->putJson(MSG_URL, reveSettings($overrides))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', $field);
})->with([
    'http base url' => [['reve_base_url' => 'http://smpp.example.test:7790'], 'reve_base_url'],
    'not a url' => [['reve_base_url' => 'smpp'], 'reve_base_url'],
    'sender id too long' => [['reve_sender_id' => '8809612345678901'], 'reve_sender_id'],
    'sender id too short' => [['reve_sender_id' => '1234'], 'reve_sender_id'],
    'sender id punctuation' => [['reve_sender_id' => '880-961-234'], 'reve_sender_id'],
    'sender id masked name (letters)' => [['reve_sender_id' => 'ToyMartBD'], 'reve_sender_id'],
    'sender id digits and letters' => [['reve_sender_id' => '8809612345ab'], 'reve_sender_id'],
    'negative cost' => [['sms_cost_per_segment' => '-0.10'], 'sms_cost_per_segment'],
    'unknown driver' => [['sms_driver' => 'twilio'], 'sms_driver'],
    'reve without sender' => [['reve_sender_id' => null], 'reve_sender_id'],
    'value and clear' => [['clear_reve_api_key' => true], 'reve_api_key'],
]);

it('accepts a non-masking numeric sender in every documented form (owner decision 2026-11-13)', function (string $sender): void {
    messagingAdmin();

    $this->putJson(MSG_URL, reveSettings(['reve_sender_id' => $sender]))->assertOk()->assertJsonPath('reve_sender_id', $sender);
})->with(['880 + 10 digits' => '8809612345678', '+880 form' => '+8809612345678', 'local 01 form' => '01712345678', 'short code' => '16263']);

it('explains a refused (masked, lettered) sender ID in English and Bangla', function (): void {
    messagingAdmin();

    $en = $this->putJson(MSG_URL, reveSettings(['reve_sender_id' => 'ToyMartBD']), ['Accept-Language' => 'en'])->assertUnprocessable();
    expect($en->json('errors.0.message'))->toContain('non-masking')->toContain('Letters are not allowed');

    $bn = $this->putJson(MSG_URL, reveSettings(['reve_sender_id' => 'ToyMartBD']), ['Accept-Language' => 'bn'])->assertUnprocessable();
    expect($bn->json('errors.0.message'))->toContain('নন-মাস্কিং');
});

it('defaults the SMS rate to the owner\'s REVE rate, ৳0.26 a segment (2026-11-13)', function (): void {
    expect(getenv('SMS_COST_PER_SEGMENT'))->toBeFalse()
        ->and((require config_path('notifications.php'))['sms']['cost_per_segment'])->toBe('0.26');
});

it('refuses REVE with no secret anywhere, but allows the log driver', function (): void {
    messagingAdmin();
    config(['notifications.sms.drivers.reve.api_key' => null]);

    $this->putJson(MSG_URL, reveSettings(['reve_api_key' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.0.field', 'reve_api_key');
    $this->putJson(MSG_URL, reveSettings(['sms_driver' => 'log', 'reve_api_key' => null, 'reve_base_url' => null, 'reve_sender_id' => null]))
        ->assertOk()
        ->assertJsonPath('reve_api_key_set', false)
        ->assertJsonPath('reve_api_key_hint', null)
        // A removed non-secret value falls back to env again.
        ->assertJsonPath('reve_base_url', 'https://env.example.test:7790');
});

it('sends one test SMS through the saved provider and reports the result', function (): void {
    messagingAdmin();
    $this->putJson(MSG_URL, reveSettings())->assertOk();
    acceptAll('4242');

    $this->postJson(MSG_URL.'/test', ['phone' => '01812345678', 'locale' => 'bn'])
        ->assertOk()
        ->assertExactJson([
            'driver' => 'reve',
            'accepted' => true,
            'message_id' => '4242',
            'segments' => 1,
            'cost' => '0.55',
            'error' => null,
        ]);
    Http::assertSent(fn (Request $r): bool => $r['toUser'] === '8801812345678'
        && $r['messageContent'] === 'টয় মার্ট বিডি: এটি একটি পরীক্ষামূলক এসএমএস।'
        && $r['apikey'] === DB_KEY);
    expect(NotificationLog::query()->latest('id')->first()?->provider_message_id)->toBe('4242');
});

it('reports a provider refusal from the test send', function (): void {
    messagingAdmin();
    $this->putJson(MSG_URL, reveSettings())->assertOk();
    Http::fake(['*' => Http::response(['Status' => '109', 'Text' => 'Invalid api key/secret key', 'Message_ID' => ''])]);

    $this->postJson(MSG_URL.'/test', ['phone' => '01712345678', 'locale' => 'en'])
        ->assertOk()
        ->assertJsonPath('accepted', false)
        ->assertJsonPath('message_id', null)
        ->assertJsonPath('error', 'reve 109: Invalid api key/secret key');
});

it('validates the test phone with rule 30 and the locale', function (string $phone, string $locale): void {
    messagingAdmin();
    Http::fake();

    $this->postJson(MSG_URL.'/test', ['phone' => $phone, 'locale' => $locale])->assertUnprocessable();
    Http::assertNothingSent();
})->with([
    'citycell 011' => ['01112345678', 'en'],
    'IoT 06x' => ['06012345678', 'en'],
    'too short' => ['0171234567', 'en'],
    'bad locale' => ['01712345678', 'fr'],
]);

it('rate-limits test sends', function (): void {
    messagingAdmin();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson(MSG_URL.'/test', ['phone' => '01712345678', 'locale' => 'en'])->assertOk(); // log driver
    }
    $this->postJson(MSG_URL.'/test', ['phone' => '01712345678', 'locale' => 'en'])->assertTooManyRequests();
});

it('uses the same settings for toymart:sms:test', function (): void {
    messagingAdmin();
    $this->putJson(MSG_URL, reveSettings())->assertOk();
    acceptAll('77');

    $this->artisan('toymart:sms:test', ['phone' => '01712345678'])
        ->expectsOutputToContain('Driver: reve')
        ->expectsOutputToContain('message id 77')
        ->assertSuccessful();
    Http::assertSent(fn (Request $r): bool => $r['apikey'] === DB_KEY);
});

it('routes order updates by the WhatsApp toggle: off → SMS, on → WhatsApp first', function (): void {
    messagingAdmin();
    $customer = Customer::factory()->create(['name' => 'নুসরাত জাহান', 'preferred_language' => 'bn']);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'grand_total' => '2450.00', 'due_total' => '2450.00']);
    OrderItem::factory()->create(['order_id' => $order->id, 'quantity' => 1, 'line_total' => '2450.00']);

    // env says on; the saved toggle says off and wins.
    config(['notifications.whatsapp.enabled' => true]);
    $this->putJson(MSG_URL, reveSettings(['sms_driver' => 'log', 'whatsapp_enabled' => false]))->assertOk()->assertJsonPath('whatsapp_enabled', false);
    expect(QueueOrderNotification::messagingChannels())->toBe([NotificationChannel::Sms]);

    OrderPlaced::dispatch($order->id, $order->customer_id);
    $first = NotificationLog::query()->where('notifiable_id', $order->id)->whereIn('channel', ['sms', 'whatsapp'])->sole();
    expect($first->channel)->toBe(NotificationChannel::Sms);

    $this->putJson(MSG_URL, reveSettings(['sms_driver' => 'log', 'whatsapp_enabled' => true]))->assertOk();
    expect(QueueOrderNotification::messagingChannels())->toBe([NotificationChannel::WhatsApp, NotificationChannel::Sms]);
});

it('defaults WhatsApp off when neither env nor settings turn it on', function (): void {
    // beforeEach mirrors the shipped default (config: env WHATSAPP_ENABLED, default false).

    expect(app(MessagingSettings::class)->whatsappEnabled())->toBeFalse()
        ->and(QueueOrderNotification::messagingChannels())->toBe([NotificationChannel::Sms]);
});

it('guards GET /settings/messaging with settings.manage_integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(MSG_URL));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('guards PUT /settings/messaging with settings.manage_integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(MSG_URL, reveSettings(['sms_driver' => 'log'])));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('guards POST /settings/messaging/test with settings.manage_integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(MSG_URL.'/test', ['phone' => '01712345678', 'locale' => 'en']));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('opens the screen to super_admin and manager (owner decision 2026-11-13), nobody else', function (): void {
    expect(RoleMatrix::allows(Role::Manager, Permission::SettingsManageIntegrations))->toBeTrue()
        ->and(RoleMatrix::allows(Role::OrderOperator, Permission::SettingsManageIntegrations))->toBeFalse()
        ->and(RoleMatrix::allows(Role::SuperAdmin, Permission::SettingsManageIntegrations))->toBeTrue();
});

it('treats an undecryptable saved secret (rotated APP_KEY) as unset, falling back to env', function (): void {
    messagingAdmin();
    DB::table('settings')->insert([
        'key' => MessagingSettings::REVE_API_KEY,
        'group' => SettingGroup::Notification->value,
        'is_encrypted' => true,
        'value' => json_encode('not-a-valid-payload'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(MSG_URL)->assertOk()
        ->assertJsonPath('reve_api_key_source', 'env')
        ->assertJsonPath('reve_api_key_hint', '••••ENV1');
});
