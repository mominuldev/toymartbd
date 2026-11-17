<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\PathaoFixtures as PF;
use Tests\Support\RoleMatrix;

/*
 * Settings › Couriers › "Check connection" (Figma B · 41 513:77225): one read-only call with the
 * SAVED credentials — Steadfast's balance, Pathao's store list. Books nothing, never verifies the
 * provider (rule 33a), never echoes a credential (rule 20), and a rejected key is reported, not
 * retried.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    (new RolesAndPermissionsSeeder)->run();
    (new DeliverySeeder)->run();
});

function checkProvider(string $code): CourierProvider
{
    return CourierProvider::query()->where('code', $code)->firstOrFail();
}

function checkAs(Role $role = Role::SuperAdmin): User
{
    $user = User::factory()->withRole($role)->create()->refresh();
    test()->actingAs($user);

    return $user;
}

function withSteadfastKeys(): CourierProvider
{
    $p = checkProvider('steadfast');
    $p->forceFill(['credentials' => ['api_key' => 'sf-key-0000111122223333', 'secret_key' => 'sf-secret-44445555']])->save();

    return $p;
}

it('is guarded like the credentials: settings.manage_integrations only', function (Role $role, bool $allowed): void {
    $steadfast = withSteadfastKeys();
    Http::fake(['https://portal.packzy.com/api/v1/get_balance' => Http::response(['status' => 200, 'current_balance' => 0])]);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson("/api/v1/admin/settings/couriers/{$steadfast->id}/check"));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('Steadfast: reads the balance with the saved keys, keeps it timestamped, and never verifies the provider', function (): void {
    $steadfast = withSteadfastKeys();
    checkAs();
    Http::fake(['https://portal.packzy.com/api/v1/get_balance' => Http::response(['status' => 200, 'current_balance' => -10])]);

    $this->postJson("/api/v1/admin/settings/couriers/{$steadfast->id}/check")
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('reason', 'connected')
        ->assertJsonPath('balance', '-10.00')
        ->assertJsonMissingPath('api_key');

    Http::assertSent(fn ($r) => $r->hasHeader('Api-Key', 'sf-key-0000111122223333') && $r->method() === 'GET');
    $fresh = $steadfast->fresh();
    expect($fresh->balance?->toDecimal())->toBe('-10.00')
        ->and($fresh->balance_checked_at)->not->toBeNull()
        ->and($fresh->verified)->toBeFalse();
});

it('Steadfast: a rejected key is credentials_rejected, sent once, nothing stored', function (): void {
    $steadfast = withSteadfastKeys();
    checkAs();
    Http::fake(['https://portal.packzy.com/api/v1/get_balance' => Http::response(['message' => 'Unauthorized'], 401)]);

    $this->postJson("/api/v1/admin/settings/couriers/{$steadfast->id}/check")
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('reason', 'credentials_rejected');

    Http::assertSentCount(1);
    expect($steadfast->fresh()->balance_checked_at)->toBeNull();
});

it('Steadfast: no saved keys asks nothing; a 5xx or garbage is unreachable', function (): void {
    checkAs();
    $steadfast = checkProvider('steadfast');
    $this->postJson("/api/v1/admin/settings/couriers/{$steadfast->id}/check")->assertJsonPath('reason', 'no_credentials');
    Http::assertNothingSent();

    withSteadfastKeys();
    Http::fake(['https://portal.packzy.com/api/v1/get_balance' => Http::sequence()->push('down', 503)->push('<html>', 200)]);
    $this->postJson("/api/v1/admin/settings/couriers/{$steadfast->id}/check")->assertJsonPath('reason', 'unreachable');
    $this->postJson("/api/v1/admin/settings/couriers/{$steadfast->id}/check")->assertJsonPath('reason', 'unreachable');
});

it('Pathao: signs in and finds the saved pickup store by id', function (): void {
    $pathao = PF::configure();
    checkAs();
    Http::fake([PF::HOST.'/issue-token' => PF::response('token.issued'), PF::HOST.'/stores' => PF::response('stores')]);

    $this->postJson("/api/v1/admin/settings/couriers/{$pathao->id}/check")
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('store_name', 'SDK Test Store f3cabd0d')
        ->assertJsonPath('sandbox', true);
    expect($pathao->fresh()->verified)->toBeFalse();
});

it('Pathao: a wrong sign-in is credentials_rejected', function (): void {
    $pathao = PF::configure();
    checkAs();
    Http::fake([PF::HOST.'/issue-token' => PF::response('token.invalid-credentials', 400)]);
    $this->postJson("/api/v1/admin/settings/couriers/{$pathao->id}/check")->assertJsonPath('ok', false)->assertJsonPath('reason', 'credentials_rejected');
});

it('Pathao: keys accepted but a pickup store id the account lacks is store_not_found', function (): void {
    $pathao = PF::configure();
    $pathao->forceFill(['credentials' => [...$pathao->credentials, 'store_id' => '999999']])->save();
    checkAs();
    Http::fake([PF::HOST.'/issue-token' => PF::response('token.issued'), PF::HOST.'/stores' => PF::response('stores')]);
    $this->postJson("/api/v1/admin/settings/couriers/{$pathao->id}/check")
        ->assertJsonPath('ok', false)
        ->assertJsonPath('reason', 'store_not_found');
});
