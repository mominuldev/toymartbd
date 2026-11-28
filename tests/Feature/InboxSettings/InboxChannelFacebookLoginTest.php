<?php

declare(strict_types=1);

use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * Inbox › Channels: "Continue with Facebook" (Meta's manual login flow on Smart Junior's own app),
 * Disconnect, the Reconnect-needed state and the card counts. Meta's answers are hand-authored
 * from its reference pages (docs/api/inbox.md) — no Meta app or Page exists. Page tokens never
 * reach the browser, never sit in plain text in the database, and never appear in an audit row.
 */

const FBL_URL = '/api/v1/admin/settings/inbox-channels';
const FBL_APP_ID = '987654321012345';
const FBL_PAGE_ID = '151784545352643';
const FBL_PAGE_TOKEN = 'EAAG-page-token-from-me-accounts-TEST-0001-long-enough';
const FBL_USER_SHORT = 'EAAG-short-user-token-TEST-0002';
const FBL_USER_LONG = 'EAAG-long-user-token-TEST-0003';
const FBL_GRAPH = 'https://graph.facebook.com/v27.0/';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
    config([
        'app.url' => 'https://admin.toymart.test',
        'inbox.graph_base_url' => 'https://graph.facebook.com',
        'inbox.graph_version' => 'v27.0',
        'inbox.messenger.app_id' => FBL_APP_ID,
        'inbox.messenger.login_config_id' => null,
    ]);
});

function fblAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create(['name' => 'Mominul Islam'])->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

/** Starts the login and returns the state Facebook would send back. */
function fblState(): string
{
    $url = test()->postJson(FBL_URL.'/messenger/login')->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return (string) $query['state'];
}

function fblFakeGraph(?array $pages = null, bool $subscribed = true): void
{
    Http::fake([
        FBL_GRAPH.'oauth/access_token*' => Http::sequence()
            ->push(['access_token' => FBL_USER_SHORT, 'token_type' => 'bearer'])
            ->push(['access_token' => FBL_USER_LONG, 'token_type' => 'bearer', 'expires_in' => 5183944]),
        FBL_GRAPH.'me/accounts*' => Http::response(['data' => $pages ?? [
            ['id' => FBL_PAGE_ID, 'name' => 'Smart Junior', 'access_token' => FBL_PAGE_TOKEN, 'tasks' => ['ANALYZE', 'ADVERTISE', 'MESSAGING', 'MODERATE', 'CREATE_CONTENT', 'MANAGE']],
        ]]),
        FBL_GRAPH.FBL_PAGE_ID.'/subscribed_apps*' => Http::response(['success' => $subscribed]),
    ]);
}

it('builds the Facebook dialog URL with a one-time state, asking for the Page permissions by name', function (): void {
    fblAdmin();

    $response = $this->postJson(FBL_URL.'/messenger/login')->assertOk();
    $url = (string) $response->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://www.facebook.com/v27.0/dialog/oauth?')
        ->and($query['client_id'])->toBe(FBL_APP_ID)
        ->and($query['redirect_uri'])->toBe('https://admin.toymart.test/admin/integrations/messenger')
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('pages_show_list,pages_manage_metadata,pages_messaging')
        ->and($query)->not->toHaveKey('config_id')
        ->and(strlen((string) $query['state']))->toBe(48)
        ->and($response->json('redirect_uri'))->toBe('https://admin.toymart.test/admin/integrations/messenger');
});

it('uses the Facebook Login for Business configuration id instead of scope when one is saved', function (): void {
    fblAdmin();
    config(['inbox.messenger.login_config_id' => '1122334455']);

    $url = (string) $this->postJson(FBL_URL.'/messenger/login')->assertOk()->json('url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query['config_id'])->toBe('1122334455')->and($query)->not->toHaveKey('scope');
});

it('refuses to start until the Meta app id and app secret exist', function (): void {
    fblAdmin();
    config(['inbox.messenger.app_id' => null]);

    $this->postJson(FBL_URL.'/messenger/login')->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');

    config(['inbox.messenger.app_id' => FBL_APP_ID, 'inbox.messenger.app_secret' => null]);
    $this->postJson(FBL_URL.'/messenger/login')->assertStatus(409);
});

it('exchanges the code, lists the shared Pages without their tokens, and connects the chosen Page', function (): void {
    $admin = fblAdmin();
    fblFakeGraph();
    $state = fblState();

    $finish = $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'AQD-code-TEST', 'state' => $state])->assertOk();
    expect($finish->json('pages'))->toBe([['id' => FBL_PAGE_ID, 'name' => 'Smart Junior', 'can_message' => true]])
        ->and($finish->getContent())->not->toContain(FBL_PAGE_TOKEN)->not->toContain(FBL_USER_LONG);

    $settings = $this->postJson(FBL_URL.'/messenger/connect', ['session' => $finish->json('session'), 'page_id' => FBL_PAGE_ID])->assertOk();
    $settings->assertJsonPath('messenger.account_id', FBL_PAGE_ID)
        ->assertJsonPath('messenger.page_name', 'Smart Junior')
        ->assertJsonPath('messenger.connected_by', 'Mominul Islam')
        ->assertJsonPath('messenger.driver', 'messenger')
        ->assertJsonPath('messenger.send_token.set', true)
        ->assertJsonPath('messenger.connection.state', 'connected')
        ->assertJsonPath('messenger.verified', false);
    expect($settings->getContent())->not->toContain(FBL_PAGE_TOKEN);

    // The code exchange sent the exact redirect URI, then the long-lived exchange.
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'oauth/access_token') && ($r->data()['code'] ?? null) === 'AQD-code-TEST'
        && $r->data()['redirect_uri'] === 'https://admin.toymart.test/admin/integrations/messenger');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'oauth/access_token') && ($r->data()['grant_type'] ?? null) === 'fb_exchange_token');
    // Subscribed with the PAGE token and the fields the driver reads.
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_ends_with($r->url(), FBL_PAGE_ID.'/subscribed_apps')
        && $r->hasHeader('Authorization', 'Bearer '.FBL_PAGE_TOKEN)
        && $r->data()['subscribed_fields'] === 'messages,messaging_postbacks,message_deliveries,message_reads');

    // Token encrypted at rest; the audit row says "changed" and never carries it.
    $raw = (string) DB::table('settings')->where('key', 'inbox.messenger.page_access_token')->value('value');
    expect($raw)->not->toContain(FBL_PAGE_TOKEN)
        ->and(app(InboxChannelSettings::class)->get(InboxChannel::Messenger, 'page_access_token'))->toBe(FBL_PAGE_TOKEN);
    $audit = AuditSensitive::query()->latest('id')->firstOrFail();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->after)->toMatchArray(['scope' => 'inbox_messenger', 'page_id' => FBL_PAGE_ID, 'page_access_token' => 'changed', 'via' => 'facebook_login', 'driver' => 'messenger'])
        ->and(json_encode([$audit->before, $audit->after]))->not->toContain(FBL_PAGE_TOKEN);

    // The session is single use.
    $this->postJson(FBL_URL.'/messenger/connect', ['session' => $finish->json('session'), 'page_id' => FBL_PAGE_ID])->assertStatus(409);
});

it('refuses a state that is unknown, used twice, or started by someone else', function (): void {
    fblAdmin();
    fblFakeGraph();
    $state = fblState();

    $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'c', 'state' => str_repeat('a', 48)])->assertStatus(409);

    // Another staff member's session cannot use it.
    $other = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($other, 'web');
    $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'c', 'state' => $state])->assertStatus(409);
    // …and that attempt used it up.
    Http::assertNothingSent();
});

it('says why when Facebook refuses the code or cannot be reached', function (): void {
    fblAdmin();
    Http::fake([FBL_GRAPH.'oauth/access_token*' => Http::response(['error' => ['message' => 'This authorization code has expired.', 'type' => 'OAuthException', 'code' => 100]], 400)]);
    $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'old', 'state' => fblState()])
        ->assertStatus(409)
        ->assertJsonPath('detail', 'Facebook did not accept the sign-in. Try Continue with Facebook again. Facebook said: This authorization code has expired.');

    Http::fake([FBL_GRAPH.'oauth/access_token*' => Http::failedConnection()]);
    $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'c', 'state' => fblState()])->assertStatus(503);
});

it('refuses a Page this account cannot message, and a Page that was not shared', function (): void {
    fblAdmin();
    fblFakeGraph([['id' => FBL_PAGE_ID, 'name' => 'Smart Junior', 'access_token' => FBL_PAGE_TOKEN, 'tasks' => ['ANALYZE']]]);
    $session = $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'c', 'state' => fblState()])->assertOk()->json('session');

    $this->postJson(FBL_URL.'/messenger/connect', ['session' => $session, 'page_id' => '999'])->assertStatus(409);
    $this->postJson(FBL_URL.'/messenger/connect', ['session' => $session, 'page_id' => FBL_PAGE_ID])->assertStatus(409);
    expect(Setting::query()->find('inbox.messenger.page_id'))->toBeNull();
});

it('saves nothing when Facebook will not subscribe the Page', function (): void {
    fblAdmin();
    fblFakeGraph(subscribed: false);
    $session = $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'c', 'state' => fblState()])->json('session');

    $this->postJson(FBL_URL.'/messenger/connect', ['session' => $session, 'page_id' => FBL_PAGE_ID])->assertStatus(409);
    expect(Setting::query()->find('inbox.messenger.page_id'))->toBeNull()
        ->and(AuditSensitive::query()->count())->toBe(0);
});

it('disconnects the Page: Meta stops sending, the saved Page and token go, replies are off, webhook keys stay', function (): void {
    $admin = fblAdmin();
    Setting::put('inbox.messenger.app_secret', InboxFixtures::FB_SECRET, SettingGroup::Notification, true);
    Setting::put('inbox.messenger.page_id', FBL_PAGE_ID, SettingGroup::Notification);
    Setting::put('inbox.messenger.page_access_token', FBL_PAGE_TOKEN, SettingGroup::Notification, true);
    Setting::put('inbox.messenger.page_name', 'Smart Junior', SettingGroup::Notification);
    Setting::put('inbox.messenger.driver', 'messenger', SettingGroup::Notification);
    Http::fake([FBL_GRAPH.FBL_PAGE_ID.'/subscribed_apps*' => Http::response(['success' => true])]);

    $this->postJson(FBL_URL.'/messenger/disconnect')->assertOk()
        ->assertJsonPath('messenger.account_id', null)
        ->assertJsonPath('messenger.page_name', null)
        ->assertJsonPath('messenger.driver', 'log')
        ->assertJsonPath('messenger.connection.state', 'not_connected')
        ->assertJsonPath('messenger.app_secret.set', true);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), FBL_PAGE_ID.'/subscribed_apps')
        && $r->hasHeader('Authorization', 'Bearer '.FBL_APP_ID.'|'.InboxFixtures::FB_SECRET));
    $audit = AuditSensitive::query()->latest('id')->firstOrFail();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->after)->toMatchArray(['scope' => 'inbox_messenger', 'page_id' => null, 'driver' => 'log', 'page_access_token' => 'cleared', 'via' => 'disconnect', 'unsubscribed' => true]);
});

it('disconnects WhatsApp without deregistering the number', function (): void {
    fblAdmin();
    Setting::put('inbox.whatsapp.phone_number_id', '106540352242922', SettingGroup::Notification);
    Setting::put('inbox.whatsapp.business_account_id', '102938475610293', SettingGroup::Notification);
    Setting::put('inbox.whatsapp.access_token', 'EAAG-wa-system-user-token-TEST', SettingGroup::Notification, true);
    Setting::put('inbox.whatsapp.driver', 'cloud', SettingGroup::Notification);

    $this->postJson(FBL_URL.'/whatsapp/disconnect')->assertOk()
        ->assertJsonPath('whatsapp.account_id', null)
        ->assertJsonPath('whatsapp.business_account_id', null)
        ->assertJsonPath('whatsapp.send_token.set', false)
        ->assertJsonPath('whatsapp.driver', 'log')
        ->assertJsonPath('whatsapp.verify_token.set', true);
    Http::assertNothingSent();

    $this->postJson(FBL_URL.'/live_chat/disconnect')->assertNotFound();
});

it('shows Reconnect needed after a failed test with error 190 or a dropped subscription, and after a send Meta refused with 190', function (): void {
    fblAdmin();
    config(['inbox.messenger.page_id' => FBL_PAGE_ID, 'inbox.messenger.page_access_token' => FBL_PAGE_TOKEN]);

    $this->getJson(FBL_URL)->assertJsonPath('messenger.connection.state', 'connected');

    // Test connection: the Page answers, but our app is no longer subscribed.
    $subscribedApp = '111';
    Http::fake(function (Request $r) use (&$subscribedApp) {
        return str_contains($r->url(), '/subscribed_apps')
            ? Http::response(['data' => [['id' => $subscribedApp, 'name' => 'An app']]])
            : Http::response(['name' => 'Smart Junior', 'id' => FBL_PAGE_ID]);
    });
    $this->postJson(FBL_URL.'/messenger/test')->assertOk()->assertJsonPath('error_code', 'not_subscribed');
    $this->getJson(FBL_URL)->assertJsonPath('messenger.connection.state', 'reconnect')->assertJsonPath('messenger.connection.reason', 'not_subscribed');

    // A passing test clears it…
    $subscribedApp = FBL_APP_ID;
    $this->travel(1)->minutes();
    $this->postJson(FBL_URL.'/messenger/test')->assertOk()->assertJsonPath('ok', true);
    $this->getJson(FBL_URL)->assertJsonPath('messenger.connection.state', 'connected');

    // …and a later send refused with 190 brings it back.
    $this->travel(1)->minutes();
    $conversation = InboxFixtures::conversation(['channel' => InboxChannel::Messenger]);
    ConversationMessage::query()->create([
        'conversation_id' => $conversation->id,
        'channel' => InboxChannel::Messenger,
        'direction' => MessageDirection::Out,
        'kind' => MessageKind::Text,
        'body' => 'জি, আছে।',
        'delivery_status' => DeliveryStatus::Failed,
        'failure_code' => 'provider_error:190',
        'failed_at' => now(),
    ]);
    $this->getJson(FBL_URL)->assertJsonPath('messenger.connection.state', 'reconnect')->assertJsonPath('messenger.connection.reason', 'meta_190');
});

it('counts open, waiting and unread chats per channel', function (): void {
    fblAdmin();
    InboxFixtures::conversation(['channel' => InboxChannel::WhatsApp, 'contact_name' => 'Farhana Yasmin', 'unread_count' => 2, 'last_inbound_at' => now()->subMinutes(4)]);
    InboxFixtures::conversation(['channel' => InboxChannel::WhatsApp, 'needs_human' => false, 'unread_count' => 0, 'last_inbound_at' => now()->subHour()]);
    InboxFixtures::conversation(['channel' => InboxChannel::Messenger, 'unread_count' => 5]);

    $this->getJson(FBL_URL)->assertOk()
        ->assertJsonPath('whatsapp.activity.open', 2)
        ->assertJsonPath('whatsapp.activity.waiting', 1)
        ->assertJsonPath('whatsapp.activity.unread', 2)
        ->assertJsonPath('whatsapp.activity.last_inbound_from', 'Farhana Yasmin')
        ->assertJsonPath('messenger.activity.unread', 5);
});

it('saves the Meta App ID and the login configuration id on the keys form', function (): void {
    fblAdmin();
    $this->putJson(FBL_URL, ['whatsapp_driver' => 'log', 'messenger_driver' => 'log', 'messenger_app_id' => '555666777', 'messenger_login_config_id' => '888999'])
        ->assertOk()
        ->assertJsonPath('messenger.app_id', '555666777')
        ->assertJsonPath('messenger.app_id_source', 'settings')
        ->assertJsonPath('messenger.login_config_id', '888999');

    $this->putJson(FBL_URL, ['whatsapp_driver' => 'log', 'messenger_driver' => 'log', 'messenger_app_id' => 'abc'])->assertUnprocessable();
});

it('guards the Facebook login and disconnect routes with settings.manage_integrations', function (Role $role, bool $allowed): void {
    Http::fake();
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(FBL_URL.'/messenger/login'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(FBL_URL.'/messenger/login/finish', ['code' => 'c', 'state' => str_repeat('a', 48)]), 409);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(FBL_URL.'/messenger/connect', ['session' => str_repeat('a', 48), 'page_id' => '1']), 409);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(FBL_URL.'/whatsapp/disconnect'));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));
