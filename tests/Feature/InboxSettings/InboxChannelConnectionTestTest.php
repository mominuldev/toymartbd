<?php

declare(strict_types=1);

use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Services\InboxChannelSettings;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Inbox\InboxFixtures;
use Tests\Support\RoleMatrix;

/*
 * Inbox channels card: "Test connection" (one read-only Graph API call), the WhatsApp Business
 * Account id, and the Messenger HUMAN_AGENT switch. Meta's responses are hand-authored from its
 * reference pages (docs/api/inbox.md) — no Meta account exists. A passing test never marks a
 * channel verified, and no token ever comes back out.
 */

const CONN_URL = '/api/v1/admin/settings/inbox-channels';
const CONN_WA_TOKEN = 'EAAG-conn-wa-token-TEST-5150';
const CONN_FB_TOKEN = 'EAAG-conn-fb-token-TEST-6160';
const CONN_WA_GRAPH = 'https://graph.facebook.com/v27.0/106540352242922*';
const CONN_FB_GRAPH = 'https://graph.facebook.com/v27.0/104857300112233*';

beforeEach(function (): void {
    Http::preventStrayRequests();
    InboxFixtures::configure();
    config([
        'app.url' => 'https://admin.toymart.test',
        'inbox.graph_base_url' => 'https://graph.facebook.com',
        'inbox.graph_version' => 'v27.0',
        'inbox.whatsapp.phone_number_id' => '106540352242922',
        'inbox.whatsapp.access_token' => CONN_WA_TOKEN,
        'inbox.messenger.page_id' => '104857300112233',
        'inbox.messenger.page_access_token' => CONN_FB_TOKEN,
    ]);
});

function connAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
    test()->actingAs($user, 'web');

    return $user;
}

it('tests WhatsApp with the phone number fields, stores the result and shows it on the card', function (): void {
    $admin = connAdmin();
    Http::fake([CONN_WA_GRAPH => Http::response(['verified_name' => 'Toy Mart BD', 'display_phone_number' => '+880 1700-000000', 'quality_rating' => 'GREEN', 'id' => '106540352242922'])]);

    $this->postJson(CONN_URL.'/whatsapp/test')->assertOk()->assertJson([
        'channel' => 'whatsapp',
        'ok' => true,
        'display_name' => 'Toy Mart BD',
        'display_number' => '+880 1700-000000',
        'quality_rating' => 'GREEN',
        'error_code' => null,
    ]);

    Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
        && $r->hasHeader('Authorization', 'Bearer '.CONN_WA_TOKEN)
        && str_contains($r->url(), 'fields=display_phone_number%2Cverified_name%2Cquality_rating')
        && ! str_contains($r->url(), 'access_token'));

    $card = $this->getJson(CONN_URL)->assertOk();
    $card->assertJsonPath('whatsapp.last_check.ok', true)
        ->assertJsonPath('whatsapp.last_check.display_name', 'Toy Mart BD')
        ->assertJsonPath('whatsapp.verified', false)
        ->assertJsonPath('messenger.last_check', null);
    expect($card->getContent())->not->toContain(CONN_WA_TOKEN);

    $audit = AuditSensitive::query()->sole();
    expect($audit->action)->toBe(AuditAction::CredentialChanged)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->after)->toMatchArray(['scope' => 'inbox_connection_test', 'channel' => 'whatsapp', 'result' => 'ok'])
        ->and(json_encode($audit->after))->not->toContain(CONN_WA_TOKEN);
});

it('tests Messenger with the Page name', function (): void {
    connAdmin();
    Http::fake([CONN_FB_GRAPH => Http::response(['name' => 'Toy Mart BD', 'id' => '104857300112233'])]);

    $this->postJson(CONN_URL.'/messenger/test')->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('display_name', 'Toy Mart BD')
        ->assertJsonPath('display_number', null);

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.CONN_FB_TOKEN) && str_contains($r->url(), 'fields=name'));
});

it('reports Meta\'s error code and message, scrubbed of the token, as ok: false', function (): void {
    connAdmin();
    Http::fake([CONN_WA_GRAPH => Http::response(['error' => [
        'message' => 'Error validating access token: token '.CONN_WA_TOKEN.' has expired.',
        'type' => 'OAuthException',
        'code' => 190,
        'fbtrace_id' => 'AbC',
    ]], 400)]);

    $response = $this->postJson(CONN_URL.'/whatsapp/test')->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error_code', 'meta_190');

    expect($response->getContent())->not->toContain(CONN_WA_TOKEN)
        ->and((string) $response->json('error_message'))->toContain('expired')
        ->and(json_encode(Setting::query()->find('inbox.whatsapp.last_check')?->value))->not->toContain(CONN_WA_TOKEN);
    expect(AuditSensitive::query()->sole()->after)->toMatchArray(['result' => 'failed', 'error_code' => 'meta_190']);
});

it('reports not_configured without calling Meta, and unreachable on a network error', function (): void {
    connAdmin();
    config(['inbox.messenger.page_access_token' => null]);

    $this->postJson(CONN_URL.'/messenger/test')->assertOk()->assertJsonPath('error_code', 'not_configured');
    Http::assertNothingSent();

    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 6 for https://graph.facebook.com/...'));
    $this->postJson(CONN_URL.'/whatsapp/test')->assertOk()->assertJsonPath('error_code', 'unreachable')->assertJsonPath('error_message', null);
});

it('asks a guest to sign in before it judges the channel', function (): void {
    // Routing must not 404 first: the admin route sweep expects 401 from every guarded route.
    $this->postJson(CONN_URL.'/product/test')->assertUnauthorized();
    $this->postJson(CONN_URL.'/whatsapp/test')->assertUnauthorized();
});

it('refuses a channel it cannot test', function (): void {
    connAdmin();
    $this->postJson(CONN_URL.'/live_chat/test')->assertNotFound();
    $this->postJson(CONN_URL.'/telegram/test')->assertNotFound();
});

it('rate-limits the test', function (): void {
    connAdmin();
    Http::fake([CONN_WA_GRAPH => Http::response(['verified_name' => 'Toy Mart BD'])]);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson(CONN_URL.'/whatsapp/test')->assertOk();
    }
    $this->postJson(CONN_URL.'/whatsapp/test')->assertStatus(429);
});

it('saves the WhatsApp Business Account id and the Messenger HUMAN_AGENT switch, audited, absent = keep', function (): void {
    connAdmin();
    $body = ['whatsapp_driver' => 'log', 'messenger_driver' => 'log'];

    $this->getJson(CONN_URL)->assertOk()
        ->assertJsonPath('whatsapp.business_account_id', null)
        ->assertJsonPath('messenger.human_agent', false)
        ->assertJsonPath('messenger.human_agent_source', 'env');

    $this->putJson(CONN_URL, [...$body, 'whatsapp_business_account_id' => '102290129340398', 'messenger_human_agent' => true])->assertOk()
        ->assertJsonPath('whatsapp.business_account_id', '102290129340398')
        ->assertJsonPath('whatsapp.business_account_id_source', 'settings')
        ->assertJsonPath('messenger.human_agent', true)
        ->assertJsonPath('messenger.human_agent_source', 'settings')
        ->assertJsonPath('whatsapp.human_agent', false);

    expect(app(InboxChannelSettings::class)->enabled(InboxChannel::Messenger, 'human_agent'))->toBeTrue()
        ->and(Setting::query()->find('inbox.messenger.human_agent')?->is_encrypted)->toBeFalse();

    $audits = AuditSensitive::query()->orderBy('id')->get();
    expect($audits->pluck('after.scope')->all())->toBe(['inbox_whatsapp', 'inbox_messenger'])
        ->and($audits[0]->after)->toMatchArray(['business_account_id' => '102290129340398'])
        ->and($audits[1]->after)->toMatchArray(['human_agent' => true]);

    // Absent keeps both; blank removes the id; false turns the switch off.
    $this->putJson(CONN_URL, $body)->assertOk()
        ->assertJsonPath('whatsapp.business_account_id', '102290129340398')
        ->assertJsonPath('messenger.human_agent', true);
    $this->putJson(CONN_URL, [...$body, 'whatsapp_business_account_id' => '', 'messenger_human_agent' => false])->assertOk()
        ->assertJsonPath('whatsapp.business_account_id', null)
        ->assertJsonPath('messenger.human_agent', false);

    $this->putJson(CONN_URL, [...$body, 'whatsapp_business_account_id' => 'WABA-12'])->assertUnprocessable()->assertJsonFragment(['field' => 'whatsapp_business_account_id']);
});

it('falls back to config for HUMAN_AGENT', function (): void {
    config(['inbox.messenger.human_agent' => true]);
    expect(app(InboxChannelSettings::class)->enabled(InboxChannel::Messenger, 'human_agent'))->toBeTrue();

    Setting::put('inbox.messenger.human_agent', false, SettingGroup::Notification);
    expect(app(InboxChannelSettings::class)->enabled(InboxChannel::Messenger, 'human_agent'))->toBeFalse();
});

it('guards POST /settings/inbox-channels/{channel}/test with settings.manage_integrations', function (Role $role, bool $allowed): void {
    Http::fake([CONN_WA_GRAPH => Http::response(['verified_name' => 'Toy Mart BD'])]);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(CONN_URL.'/whatsapp/test'));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));
