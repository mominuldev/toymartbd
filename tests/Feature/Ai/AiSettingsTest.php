<?php

declare(strict_types=1);

use App\Domain\Ai\Services\AiDrivers;
use App\Models\AuditSensitive;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Setting;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

const AI_SETTINGS_URL = '/api/v1/admin/settings/ai';

beforeEach(function (): void {
    $this->fake = AiDrivers::fake();
});

function aiSettingsBody(array $override = []): array
{
    $features = [];
    foreach (['advisor', 'product_copy', 'inbox_reply', 'restock_note'] as $f) {
        $features[$f] = ['provider' => 'anthropic', 'model' => 'claude-opus-5-5'];
    }

    return array_replace_recursive(['features' => $features, 'cap_usd' => '50.00', 'share' => ['figures' => true, 'products' => true, 'first_names' => true]], $override);
}

it('shows the defaults: nothing connected, Claude Opus 5.5 everywhere, a $50 cap', function (): void {
    Support::actingAs(Role::SuperAdmin);

    $this->getJson(AI_SETTINGS_URL)->assertOk()
        ->assertJsonPath('providers.0.provider', 'anthropic')
        ->assertJsonPath('providers.0.connected', false)
        ->assertJsonPath('providers.1.default_model', 'gpt-6-astra')
        ->assertJsonPath('features.0', ['feature' => 'advisor', 'provider' => 'anthropic', 'model' => 'claude-opus-5-5', 'connected' => false])
        ->assertJsonPath('cap_usd', '50.00')
        ->assertJsonPath('usage.spent_usd', '0.00')
        ->assertJsonPath('can', ['update' => true, 'manage_keys' => true]);
});

it('checks a key before saving it encrypted, never returns it, and audits the change', function (): void {
    $user = Support::actingAs(Role::SuperAdmin);

    $res = $this->putJson(AI_SETTINGS_URL.'/providers/anthropic', ['api_key' => 'sk-ant-api03-secret-7Hk2'])->assertOk()
        ->assertJsonPath('providers.0.connected', true)
        ->assertJsonPath('providers.0.last4', '7Hk2')
        ->assertJsonPath('providers.0.check_ok', true);

    expect(json_encode($res->json()))->not->toContain('secret');
    $row = Setting::query()->findOrFail('ai.key.anthropic');
    expect($row->is_encrypted)->toBeTrue()->and($row->getRawOriginal('value'))->not->toContain('secret')->and($row->value)->toBe('sk-ant-api03-secret-7Hk2');
    $audit = AuditSensitive::query()->sole();
    expect($audit->user_id)->toBe($user->id)->and($audit->after)->toBe(['scope' => 'ai_anthropic', 'api_key' => 'changed']);
});

it('answers ai-unavailable and stores nothing when the provider rejects the key', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $this->putJson(AI_SETTINGS_URL.'/providers/openai', ['api_key' => 'bad-key-0000000000000000'])
        ->assertStatus(503)->assertJsonPath('type', 'https://toymartbd.com/problems/ai-unavailable')->assertJsonPath('errors.0.message', 'auth_failed');
    expect(Setting::query()->find('ai.key.openai'))->toBeNull()->and(AuditSensitive::query()->count())->toBe(0);
});

it('disconnects a provider, audited', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $this->putJson(AI_SETTINGS_URL.'/providers/gemini', ['api_key' => 'AIza-test-000000000000'])->assertOk();

    $this->deleteJson(AI_SETTINGS_URL.'/providers/gemini')->assertOk()->assertJsonPath('providers.2.connected', false);
    expect(Setting::query()->find('ai.key.gemini'))->toBeNull()
        ->and(AuditSensitive::query()->latest('id')->first()->after)->toBe(['scope' => 'ai_gemini', 'api_key' => 'cleared']);
});

it('saves routing, cap and sharing, and rejects a model the provider does not offer', function (): void {
    Support::actingAs(Role::SuperAdmin);

    $this->putJson(AI_SETTINGS_URL, aiSettingsBody(['features' => ['restock_note' => ['provider' => 'gemini', 'model' => 'gemini-3.5-flash-lite']], 'cap_usd' => '20', 'share' => ['first_names' => false]]))->assertOk()
        ->assertJsonPath('features.3.provider', 'gemini')
        ->assertJsonPath('features.3.model', 'gemini-3.5-flash-lite')
        ->assertJsonPath('cap_usd', '20.00')
        ->assertJsonPath('share.first_names', false);

    $this->putJson(AI_SETTINGS_URL, aiSettingsBody(['features' => ['advisor' => ['provider' => 'openai', 'model' => 'claude-opus-5-5']]]))
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'features.advisor.model');
    $this->putJson(AI_SETTINGS_URL, aiSettingsBody(['cap_usd' => '-1']))->assertStatus(422);
    $this->putJson(AI_SETTINGS_URL, ['cap_usd' => '10'])->assertStatus(422);
});

it('rechecks a saved key', function (): void {
    Support::actingAs(Role::SuperAdmin);
    $this->putJson(AI_SETTINGS_URL.'/providers/anthropic', ['api_key' => 'sk-ant-api03-0000000000'])->assertOk();

    $this->postJson(AI_SETTINGS_URL.'/providers/anthropic/check')->assertOk()->assertJsonPath('providers.0.check_ok', true);
    $this->postJson(AI_SETTINGS_URL.'/providers/openai/check')->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/ai-not-configured');
});

describe('role matrix', function (): void {
    it('guards GET /settings/ai with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(AI_SETTINGS_URL));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards PUT /settings/ai with settings.update', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(AI_SETTINGS_URL, aiSettingsBody()));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));

    it('guards the key routes with settings.manage_integrations', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(AI_SETTINGS_URL.'/providers/anthropic', ['api_key' => 'sk-ant-api03-0000000000']));
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(AI_SETTINGS_URL.'/providers/anthropic/check'));
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson(AI_SETTINGS_URL.'/providers/anthropic'));
    })->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

    it('refuses a guest with 401', function (): void {
        $this->getJson(AI_SETTINGS_URL)->assertUnauthorized();
    });
});

it('saves a key the provider accepts but rate-limits, and flags it as limited', function (): void {
    Support::actingAs(Role::SuperAdmin);

    $this->putJson(AI_SETTINGS_URL.'/providers/gemini', ['api_key' => 'limited-key-0000000000000'])->assertOk()
        ->assertJsonPath('providers.2.connected', true)
        ->assertJsonPath('providers.2.check_ok', true)
        ->assertJsonPath('providers.2.limited', true);
    expect(Setting::query()->find('ai.key.gemini'))->not->toBeNull();
});
