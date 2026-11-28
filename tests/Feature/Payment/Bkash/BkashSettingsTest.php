<?php

declare(strict_types=1);

use App\Domain\Payment\Models\PaymentProvider;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Payment\Bkash\Fixtures;
use Tests\Support\RoleMatrix;

/*
 * Integrations › Payments for bKash: the same settings API as SSLCommerz and PayStation, with
 * four credentials (username, password, app key, app secret) that are ALL write-only — the API
 * returns only whether each is set and a "••••1234" hint (rule 20). Saves are audited by field
 * name only. Rule 21: the role matrix on the bKash code. The network is never called.
 */

const BK_LIVE_SECRET = 'live-app-secret-7f3a9c11';

const BK_LIVE_PASSWORD = 'live-password-5e2b8d44';

beforeEach(function (): void {
    Http::preventStrayRequests();
    (new RolesAndPermissionsSeeder)->run();
    config(['payments.bkash' => array_merge(config('payments.bkash'), ['username' => null, 'password' => null, 'app_key' => null, 'app_secret' => null])]);
});

function bkAdmin(Role $role = Role::SuperAdmin): User
{
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user);

    return $user;
}

function bkRow(): PaymentProvider
{
    return PaymentProvider::query()->where('code', 'bkash')->firstOrFail();
}

/** @return array<string, string> */
function bkLiveKeys(): array
{
    return ['username' => 'tm-live-user', 'password' => BK_LIVE_PASSWORD, 'app_key' => 'live-app-key-9q2w', 'app_secret' => BK_LIVE_SECRET];
}

describe('role matrix (rule 21)', function (): void {
    it('guards PUT /settings/payment-gateways/bkash', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson('/api/v1/admin/settings/payment-gateways/bkash', [
            'credentials' => ['sandbox' => ['username' => 'user-'.$role->value]],
        ]));
    })->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

    it('guards POST /settings/payment-gateways/bkash/check', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson('/api/v1/admin/settings/payment-gateways/bkash/check'));
    })->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));
});

it('starts off, unverified and in sandbox, with four write-only fields', function (): void {
    bkAdmin();

    $this->getJson('/api/v1/admin/settings/payment-gateways')->assertOk()
        ->assertJsonPath('data.2.code', 'bkash')
        ->assertJsonPath('data.2.name', 'bKash')
        ->assertJsonPath('data.2.is_active', false)
        ->assertJsonPath('data.2.verified', false)
        ->assertJsonPath('data.2.mode', 'sandbox')
        ->assertJsonPath('data.2.configured', false)
        ->assertJsonPath('data.2.ipn_url', null)
        ->assertJsonPath('data.2.hosts', ['sandbox' => 'tokenized.sandbox.bka.sh', 'live' => null])
        ->assertJsonPath('data.2.credentials.sandbox.fields.*.key', ['username', 'password', 'app_key', 'app_secret'])
        ->assertJsonPath('data.2.credentials.sandbox.fields.*.secret', [true, true, true, true]);
});

it('stores keys encrypted, returns only set + a last-four hint, and audits field names only', function (): void {
    $user = bkAdmin();

    $response = $this->putJson('/api/v1/admin/settings/payment-gateways/bkash', ['credentials' => ['live' => bkLiveKeys()]])->assertOk()
        ->assertJsonPath('credentials.live.complete', true)
        ->assertJsonPath('credentials.live.fields.3', ['key' => 'app_secret', 'secret' => true, 'set' => true, 'value' => null, 'hint' => '••••9c11'])
        ->assertJsonPath('credentials.live.fields.1.hint', '••••8d44')
        ->assertJsonPath('credentials.sandbox.fields.0', ['key' => 'username', 'secret' => true, 'set' => false, 'value' => null, 'hint' => null]);

    expect($response->getContent())->not->toContain(BK_LIVE_SECRET)->not->toContain(BK_LIVE_PASSWORD)->not->toContain('tm-live-user')
        ->and(bkRow()->credentials['live'])->toBe(bkLiveKeys());
    $raw = (string) DB::table('payment_gateways')->where('code', 'bkash')->value('credentials');
    expect($raw)->not->toContain(BK_LIVE_SECRET);

    $audit = AuditSensitive::query()->where('action', AuditAction::CredentialChanged->value)->latest('id')->firstOrFail();
    expect($audit->user_id)->toBe($user->id)
        ->and($audit->after)->toBe(['gateway' => 'bkash', 'changed' => ['live.username', 'live.password', 'live.app_key', 'live.app_secret']])
        ->and(json_encode($audit->after))->not->toContain(BK_LIVE_SECRET);
});

it('refuses a key bKash does not use, and switching on without a complete set', function (): void {
    bkAdmin();

    $this->putJson('/api/v1/admin/settings/payment-gateways/bkash', ['credentials' => ['sandbox' => ['store_id' => 'x']]])
        ->assertStatus(422);
    $this->putJson('/api/v1/admin/settings/payment-gateways/bkash', ['is_active' => true, 'credentials' => ['sandbox' => ['username' => 'only-one']]])
        ->assertStatus(422);
    $this->putJson('/api/v1/admin/settings/payment-gateways/bkash', ['mode' => 'live'])->assertStatus(422);
    expect(bkRow()->is_active)->toBeFalse()->and(bkRow()->sandbox)->toBeTrue();
});

it('goes live with a complete live set but takes no production payment until verified', function (): void {
    config(['payments.bkash.hosts.live' => Fixtures::LIVE]);
    bkAdmin();

    $this->putJson('/api/v1/admin/settings/payment-gateways/bkash', ['is_active' => true, 'mode' => 'live', 'credentials' => ['live' => bkLiveKeys()]])
        ->assertOk()
        ->assertJsonPath('mode', 'live')
        ->assertJsonPath('configured', true)
        ->assertJsonPath('status.enabled', true);

    app()->detectEnvironment(fn (): string => 'production');
    try {
        $this->getJson('/api/v1/admin/settings/payment-gateways')->assertOk()
            ->assertJsonPath('data.2.status.enabled', false)
            ->assertJsonPath('data.2.status.reason', 'unverified');
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('runs Check connection with a grant-token call for the saved mode', function (): void {
    Fixtures::configure(active: false);
    $sandbox = Fixtures::sandbox();
    bkAdmin();

    $this->postJson('/api/v1/admin/settings/payment-gateways/bkash/check')->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('code', 'ok')
        ->assertJsonPath('mode', 'sandbox');
    expect($sandbox->calls['grant'])->toBe(1)->and($sandbox->calls['create'])->toBe(0);
});

it('lists bKash on the Integrations hub as test mode, linked to the payments screen', function (): void {
    Fixtures::configure();
    bkAdmin();

    $item = collect($this->getJson('/api/v1/admin/integrations')->assertOk()->json('data'))->firstWhere('key', 'bkash');
    expect($item)->not->toBeNull()
        ->and($item['status'])->toBe('test_mode')
        ->and($item['notes'])->toBe(['sandbox'])
        ->and($item['settings_path'])->toBe('/integrations/payments');
});
