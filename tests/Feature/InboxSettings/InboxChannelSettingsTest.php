<?php

declare(strict_types=1);

use App\Domain\Inbox\Drivers\LogChannelDriver;
use App\Domain\Inbox\Drivers\WhatsAppCloudDriver;
use App\Domain\Inbox\DTOs\OutboundMessage;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\InboxWebhookReceipt;
use App\Domain\Inbox\Services\InboxChannels;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Domain\Inbox\Support\MetaSignature;
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
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * Admin → Integrations → Messaging, inbox channels: WhatsApp Cloud API and Messenger
 * credentials entered in the admin, encrypted in `settings`, env as the fallback. Secrets never
 * come back out — not in the API, not in a log, not in the audit trail — and saving them never
 * marks a channel verified (no real Meta account exists, 2026-11-15).
 */

const INBOX_CREDS_URL = '/api/v1/admin/settings/inbox-channels';
const WA_TOKEN_SAVED = 'EAAG-wa-access-token-SAVED-7781';
const WA_SECRET_SAVED = 'wa-app-secret-SAVED-4410';
const WA_VERIFY_SAVED = 'wa-verify-SAVED-3302';
const FB_TOKEN_SAVED = 'EAAG-fb-page-token-SAVED-5519';
const FB_SECRET_SAVED = 'fb-app-secret-SAVED-6620';
const FB_VERIFY_SAVED = 'fb-verify-SAVED-9913';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure(); // env: log drivers, fixture app secrets / verify tokens, no send creds
    config(['app.url' => 'https://admin.toymart.test']);
});

function inboxCredsAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** @return array<string, mixed> */
function inboxCredsBody(array $overrides = []): array
{
    return [
        'whatsapp_driver' => 'cloud',
        'whatsapp_phone_number_id' => '106540352242922',
        'whatsapp_access_token' => WA_TOKEN_SAVED,
        'whatsapp_app_secret' => WA_SECRET_SAVED,
        'whatsapp_verify_token' => WA_VERIFY_SAVED,
        'messenger_driver' => 'messenger',
        'messenger_page_id' => '104857300112233',
        'messenger_page_access_token' => FB_TOKEN_SAVED,
        'messenger_app_secret' => FB_SECRET_SAVED,
        'messenger_verify_token' => FB_VERIFY_SAVED,
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function inboxCredsLogOnly(array $overrides = []): array
{
    return ['whatsapp_driver' => 'log', 'messenger_driver' => 'log', ...$overrides];
}

/** @return list<string> */
function inboxCredsSecrets(): array
{
    return [WA_TOKEN_SAVED, WA_SECRET_SAVED, WA_VERIFY_SAVED, FB_TOKEN_SAVED, FB_SECRET_SAVED, FB_VERIFY_SAVED];
}

it('reads env fallbacks, shows secrets only as set + source + last-4 hint, and gives absolute webhook URLs', function (): void {
    inboxCredsAdmin();

    $response = $this->getJson(INBOX_CREDS_URL)->assertOk();

    $response->assertJson([
        'whatsapp' => [
            'channel' => 'whatsapp',
            'driver' => 'log',
            'driver_source' => 'env',
            'account_id' => null,
            'account_id_source' => null,
            'send_token' => ['set' => false, 'hint' => null, 'source' => null],
            'app_secret' => ['set' => true, 'hint' => '••••0001', 'source' => 'env'],
            'verify_token' => ['set' => true, 'hint' => '••••0001', 'source' => 'env'],
            'webhook_url' => 'https://admin.toymart.test/api/v1/webhooks/inbox/whatsapp',
            'webhook_ready' => true,
            'send_ready' => false,
            'verified' => false,
        ],
        'messenger' => [
            'channel' => 'messenger',
            'driver' => 'log',
            'app_secret' => ['set' => true, 'hint' => '••••0002', 'source' => 'env'],
            'webhook_url' => 'https://admin.toymart.test/api/v1/webhooks/inbox/messenger',
            'webhook_ready' => true,
            'send_ready' => false,
            'verified' => false,
        ],
    ]);
    expect($response->getContent())->not->toContain(InboxFixtures::WA_SECRET)->not->toContain(InboxFixtures::FB_SECRET)
        ->not->toContain(InboxFixtures::WA_VERIFY)->not->toContain(InboxFixtures::FB_VERIFY);
});

it('round-trips: saves to settings, encrypts secrets at rest, returns only hints, stays unverified', function (): void {
    $user = inboxCredsAdmin();

    $response = $this->putJson(INBOX_CREDS_URL, inboxCredsBody())->assertOk()
        ->assertJson([
            'whatsapp' => [
                'driver' => 'cloud',
                'driver_source' => 'settings',
                'account_id' => '106540352242922',
                'account_id_source' => 'settings',
                'send_token' => ['set' => true, 'hint' => '••••7781', 'source' => 'settings'],
                'app_secret' => ['set' => true, 'hint' => '••••4410', 'source' => 'settings'],
                'verify_token' => ['set' => true, 'hint' => '••••3302', 'source' => 'settings'],
                'webhook_ready' => true,
                'send_ready' => true,
                'verified' => false,
            ],
            'messenger' => [
                'driver' => 'messenger',
                'account_id' => '104857300112233',
                'send_token' => ['set' => true, 'hint' => '••••5519', 'source' => 'settings'],
                'app_secret' => ['hint' => '••••6620'],
                'verify_token' => ['hint' => '••••9913'],
                'send_ready' => true,
                'verified' => false,
            ],
        ]);

    $get = $this->getJson(INBOX_CREDS_URL)->assertOk();
    foreach (inboxCredsSecrets() as $secret) {
        expect($response->getContent())->not->toContain($secret);
        expect($get->getContent())->not->toContain($secret);
    }

    // Raw column: an encrypted payload, not the plaintext; non-secrets stay plain.
    foreach (['access_token' => WA_TOKEN_SAVED, 'app_secret' => WA_SECRET_SAVED, 'verify_token' => WA_VERIFY_SAVED] as $name => $secret) {
        $raw = DB::table('settings')->where('key', 'inbox.whatsapp.'.$name)->first();
        expect($raw->is_encrypted)->toBeTruthy()
            ->and($raw->value)->not->toContain($secret)
            ->and($raw->updated_by)->toBe($user->id)
            ->and($raw->group)->toBe(SettingGroup::Notification->value);
    }
    expect(DB::table('settings')->where('key', 'inbox.messenger.page_access_token')->value('value'))->not->toContain(FB_TOKEN_SAVED);
    expect(DB::table('settings')->where('key', 'inbox.whatsapp.phone_number_id')->value('is_encrypted'))->toBeFalsy();
    expect(Setting::query()->find('inbox.messenger.app_secret')?->value)->toBe(FB_SECRET_SAVED);

    // The Integrations hub reads the same summary: still not verified.
    foreach (app(InboxChannels::class)->summary() as $row) {
        expect($row['verified'])->toBeFalse();
    }
});

it('keeps a saved secret when the field is blank, and clearing falls back to env', function (): void {
    inboxCredsAdmin();
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody())->assertOk();

    // Blank secrets keep what is saved.
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody([
        'whatsapp_access_token' => null, 'whatsapp_app_secret' => '', 'whatsapp_verify_token' => null,
    ]))->assertOk()->assertJsonPath('whatsapp.app_secret.hint', '••••4410');
    expect(app(InboxChannelSettings::class)->get(InboxChannel::WhatsApp, 'app_secret'))->toBe(WA_SECRET_SAVED);

    // Clearing removes the saved one; env (the fixture secret) applies again.
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody(['whatsapp_app_secret' => null, 'clear_whatsapp_app_secret' => true]))
        ->assertOk()
        ->assertJsonPath('whatsapp.app_secret', ['set' => true, 'hint' => '••••0001', 'source' => 'env']);
    expect(Setting::query()->find('inbox.whatsapp.app_secret'))->toBeNull();

    // Clearing with nothing in env leaves it unset, and the webhook stops being ready.
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody(['messenger_driver' => 'log', 'messenger_page_access_token' => null, 'clear_messenger_page_access_token' => true]))
        ->assertOk()
        ->assertJsonPath('messenger.send_token', ['set' => false, 'hint' => null, 'source' => null])
        ->assertJsonPath('messenger.send_ready', false);

    // A blank id removes the saved one.
    $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly())->assertOk()
        ->assertJsonPath('whatsapp.account_id', null)
        ->assertJsonPath('whatsapp.driver', 'log');
});

it('hints a short secret with dots only', function (): void {
    inboxCredsAdmin();

    $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly(['whatsapp_verify_token' => 'short-9876']))->assertOk()
        ->assertJsonPath('whatsapp.verify_token', ['set' => true, 'hint' => '••••', 'source' => 'settings']);
});

it('validates drivers, ids and secret/clear conflicts', function (array $body, string $field): void {
    inboxCredsAdmin();

    $this->putJson(INBOX_CREDS_URL, $body)->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error')
        ->assertJsonFragment(['field' => $field]);
})->with([
    'missing whatsapp driver' => [['messenger_driver' => 'log'], 'whatsapp_driver'],
    'unknown whatsapp driver' => [inboxCredsLogOnly(['whatsapp_driver' => 'messenger']), 'whatsapp_driver'],
    'unknown messenger driver' => [inboxCredsLogOnly(['messenger_driver' => 'cloud']), 'messenger_driver'],
    'lettered phone number id' => [inboxCredsLogOnly(['whatsapp_phone_number_id' => '+8801712345678']), 'whatsapp_phone_number_id'],
    'lettered page id' => [inboxCredsLogOnly(['messenger_page_id' => 'toymartbd']), 'messenger_page_id'],
    'value and clear' => [inboxCredsLogOnly(['whatsapp_app_secret' => 'x', 'clear_whatsapp_app_secret' => true]), 'whatsapp_app_secret'],
]);

it('refuses the Meta driver without send credentials after the save', function (): void {
    inboxCredsAdmin();

    $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly(['whatsapp_driver' => 'cloud']))->assertUnprocessable()
        ->assertJsonFragment(['field' => 'whatsapp_phone_number_id'])
        ->assertJsonFragment(['field' => 'whatsapp_access_token']);
    $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly(['messenger_driver' => 'messenger', 'messenger_page_id' => '1048573']))->assertUnprocessable()
        ->assertJsonFragment(['field' => 'messenger_page_access_token']);

    // Saved token kept by a blank field satisfies it; clearing it in the same save does not.
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody())->assertOk();
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody(['whatsapp_access_token' => null]))->assertOk();
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody(['whatsapp_access_token' => null, 'clear_whatsapp_access_token' => true]))->assertUnprocessable()
        ->assertJsonFragment(['field' => 'whatsapp_access_token']);

    // The log driver needs nothing.
    $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly())->assertOk();
    expect(AuditSensitive::query()->count())->toBeGreaterThan(0);
});

it('accepts the Meta driver when the send credentials come from env', function (): void {
    inboxCredsAdmin();
    config(['inbox.whatsapp.phone_number_id' => '1065403', 'inbox.whatsapp.access_token' => 'EAAG-env-token-0000-ENV9']);

    $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly(['whatsapp_driver' => 'cloud']))->assertOk()
        ->assertJsonPath('whatsapp.account_id_source', 'env')
        ->assertJsonPath('whatsapp.send_token.source', 'env')
        ->assertJsonPath('whatsapp.send_ready', true);
});

it('audits each changed channel with its scope, never a secret value', function (): void {
    $user = inboxCredsAdmin();

    $this->putJson(INBOX_CREDS_URL, inboxCredsBody())->assertOk();
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody(array_fill_keys([
        'whatsapp_access_token', 'whatsapp_app_secret', 'whatsapp_verify_token',
        'messenger_page_access_token', 'messenger_app_secret', 'messenger_verify_token',
    ], null)))->assertOk(); // no change → no row

    $rows = AuditSensitive::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    [$wa, $fb] = [$rows[0], $rows[1]];
    expect($wa->action)->toBe(AuditAction::CredentialChanged)
        ->and($wa->user_id)->toBe($user->id)
        ->and($wa->before)->toBe(['scope' => 'inbox_whatsapp', 'driver' => null, 'phone_number_id' => null])
        ->and($wa->after)->toBe([
            'scope' => 'inbox_whatsapp',
            'driver' => 'cloud',
            'phone_number_id' => '106540352242922',
            'access_token' => 'changed',
            'app_secret' => 'changed',
            'verify_token' => 'changed',
        ])
        ->and($fb->after)->toMatchArray(['scope' => 'inbox_messenger', 'driver' => 'messenger', 'page_id' => '104857300112233', 'page_access_token' => 'changed']);

    $json = (string) json_encode($rows->map(fn (AuditSensitive $a): array => [$a->before, $a->after])->all());
    foreach (inboxCredsSecrets() as $secret) {
        expect($json)->not->toContain($secret)->not->toContain(mb_substr($secret, -4));
    }

    $before = AuditSensitive::query()->count();
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody([
        ...array_fill_keys(['whatsapp_access_token', 'whatsapp_app_secret', 'whatsapp_verify_token', 'messenger_page_access_token', 'messenger_app_secret'], null),
        'messenger_verify_token' => null,
        'clear_messenger_verify_token' => true,
    ]))->assertOk();
    expect(AuditSensitive::query()->count())->toBe($before + 1)
        ->and(AuditSensitive::query()->latest('id')->first()?->after)->toBe(['scope' => 'inbox_messenger', 'verify_token' => 'cleared']);
});

it('never writes a secret to the log while saving, failing validation, sending or receiving', function (): void {
    inboxCredsAdmin();
    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = json_encode([$message->message, $message->context]);
    });
    Http::fake(['*' => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 401)]);

    $this->putJson(INBOX_CREDS_URL, inboxCredsBody())->assertOk();
    $this->putJson(INBOX_CREDS_URL, inboxCredsBody(['whatsapp_phone_number_id' => 'abc']))->assertUnprocessable();
    app(InboxChannels::class)->sender(InboxChannel::WhatsApp)->send(new OutboundMessage(InboxChannel::WhatsApp, 1, '8801841523907', 'hello'));
    $body = InboxFixtures::body('whatsapp-text');
    $this->call('POST', '/api/v1/webhooks/inbox/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => MetaSignature::sign($body, 'wrong')], $body);

    $all = implode("\n", $logged);
    foreach (inboxCredsSecrets() as $secret) {
        expect($all)->not->toContain($secret);
    }
});

it('sends with the saved driver and token: the Meta driver actually uses them', function (): void {
    inboxCredsAdmin();
    expect(app(InboxChannels::class)->sender(InboxChannel::WhatsApp))->toBeInstanceOf(LogChannelDriver::class);

    $this->putJson(INBOX_CREDS_URL, inboxCredsBody())->assertOk();
    Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.SAVED1']]])]);

    $driver = app(InboxChannels::class)->sender(InboxChannel::WhatsApp);
    expect($driver)->toBeInstanceOf(WhatsAppCloudDriver::class)->and($driver->isVerified())->toBeFalse();

    $result = $driver->send(new OutboundMessage(InboxChannel::WhatsApp, 1, '8801841523907', 'আপনার অর্ডার পাঠানো হয়েছে'));

    expect($result->accepted)->toBeTrue();
    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/106540352242922/messages')
        && $r->hasHeader('Authorization', 'Bearer '.WA_TOKEN_SAVED));
});

it('verifies webhooks with the saved app secret and verify token (end to end), not the env ones', function (): void {
    inboxCredsAdmin();
    $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly([
        'whatsapp_app_secret' => WA_SECRET_SAVED,
        'whatsapp_verify_token' => WA_VERIFY_SAVED,
        'messenger_app_secret' => FB_SECRET_SAVED,
    ]))->assertOk();

    $post = fn (string $channel, string $body, string $secret) => $this->call('POST', '/api/v1/webhooks/inbox/'.$channel, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => MetaSignature::sign($body, $secret),
    ], $body);

    // The env (fixture) secret no longer opens the door.
    $wa = InboxFixtures::body('whatsapp-text');
    $post('whatsapp', $wa, InboxFixtures::WA_SECRET)->assertStatus(401);
    expect(InboxWebhookReceipt::query()->count())->toBe(0);

    // The saved one does, and the message lands in the inbox.
    $post('whatsapp', $wa, WA_SECRET_SAVED)->assertOk()->assertJson(['received' => true]);
    expect(Conversation::query()->where('channel', InboxChannel::WhatsApp)->count())->toBe(1);

    $fb = InboxFixtures::body('messenger-text');
    $post('messenger', $fb, InboxFixtures::FB_SECRET)->assertStatus(401);
    $post('messenger', $fb, FB_SECRET_SAVED)->assertOk();

    // Handshake: the saved verify token, not env's.
    $this->get('/api/v1/webhooks/inbox/whatsapp?hub.mode=subscribe&hub.verify_token='.WA_VERIFY_SAVED.'&hub.challenge=1158201444')
        ->assertOk()->assertSee('1158201444', false);
    $this->getJson('/api/v1/webhooks/inbox/whatsapp?hub.mode=subscribe&hub.verify_token='.InboxFixtures::WA_VERIFY.'&hub.challenge=1')->assertStatus(403);
    // Messenger's verify token was not saved: env still applies.
    $this->get('/api/v1/webhooks/inbox/messenger?hub.mode=subscribe&hub.verify_token='.InboxFixtures::FB_VERIFY.'&hub.challenge=7')->assertOk();
});

it('treats an undecryptable saved secret (rotated APP_KEY) as unset, falling back to env', function (): void {
    inboxCredsAdmin();
    DB::table('settings')->insert([
        'key' => 'inbox.whatsapp.app_secret',
        'group' => SettingGroup::Notification->value,
        'is_encrypted' => true,
        'value' => json_encode('not-a-valid-payload'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->getJson(INBOX_CREDS_URL)->assertOk()
        ->assertJsonPath('whatsapp.app_secret', ['set' => true, 'hint' => '••••0001', 'source' => 'env']);
});

it('guards GET /settings/inbox-channels with settings.manage_integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(INBOX_CREDS_URL));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('guards PUT /settings/inbox-channels with settings.manage_integrations', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(INBOX_CREDS_URL, inboxCredsLogOnly()));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));
