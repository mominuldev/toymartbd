<?php

declare(strict_types=1);

use App\Domain\Pos\Services\PosDiscountSettings;
use App\Domain\Pos\Services\PosDueSettings;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Orders\Support;
use Tests\Feature\Pos\PosKit;
use Tests\Support\RoleMatrix;

/*
 * Store settings › Point of sale: the cap on a cashier's discount (rule 33d, 10% until saved)
 * and the due limit per customer (rule 33c, ৳5,000 until saved), applied from the next quote.
 */

const POS_SETTINGS_URL = '/api/v1/admin/settings/pos';

it('shows the defaults until saved, then the saved values, with who saved them', function (): void {
    $user = Support::actingAs(Role::Manager);

    $this->getJson(POS_SETTINGS_URL)->assertOk()->assertExactJson([
        'discount_max_percent' => '10', 'discount_max_percent_default' => '10', 'discount_saved' => false,
        'due_limit' => '5000.00', 'due_limit_default' => '5000.00', 'due_saved' => false,
    ]);

    $this->putJson(POS_SETTINGS_URL, ['discount_max_percent' => '12.50', 'due_limit' => '7500'])->assertOk()
        ->assertJsonPath('discount_max_percent', '12.5')
        ->assertJsonPath('discount_saved', true)
        ->assertJsonPath('due_limit', '7500.00')
        ->assertJsonPath('due_saved', true);
    $this->getJson(POS_SETTINGS_URL)->assertOk()->assertJsonPath('discount_max_percent', '12.5')->assertJsonPath('due_limit', '7500.00');
    expect(Setting::query()->findOrFail(PosDiscountSettings::KEY)->updated_by)->toBe($user->id)
        ->and(Setting::query()->findOrFail(PosDueSettings::KEY)->updated_by)->toBe($user->id)
        ->and((string) app(PosDueSettings::class)->limit())->toBe('7500.00');

    $this->putJson(POS_SETTINGS_URL, ['discount_max_percent' => '0', 'due_limit' => '0'])->assertOk()
        ->assertJsonPath('discount_max_percent', '0')
        ->assertJsonPath('due_limit', '0.00');
});

it('rejects a cap that is not a percent from 0 to 100', function (string $value): void {
    Support::actingAs(Role::Manager);
    $this->putJson(POS_SETTINGS_URL, ['discount_max_percent' => $value, 'due_limit' => '5000'])->assertStatus(422)->assertJsonPath('errors.0.field', 'discount_max_percent');
})->with(['100.01', '-1', 'ten', '1.234', '']);

it('rejects a due limit that is not taka', function (string $value): void {
    Support::actingAs(Role::Manager);
    $this->putJson(POS_SETTINGS_URL, ['discount_max_percent' => '10', 'due_limit' => $value])->assertStatus(422)->assertJsonPath('errors.0.field', 'due_limit');
})->with(['-1', '5,000', '12.345', 'five', '']);

it('applies the saved cap to the next POS quote', function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $branch = PosKit::branch();
    $cashier = PosKit::cashier();
    $session = PosKit::open($branch, $cashier);
    $toy = PosKit::variant($branch, 5, '1000.00');
    $quote = fn () => $this->postJson("/api/v1/admin/pos/sessions/{$session->id}/quote", [
        'lines' => PosKit::lines([$toy->id => 1]),
        'discount' => ['type' => 'percent', 'value' => '15', 'reason' => 'bulk_purchase'],
    ]);

    $quote()->assertOk()->assertJsonPath('blockers.0.field', 'discount');

    auth()->forgetGuards();
    Support::actingAs(Role::Manager);
    $this->putJson(POS_SETTINGS_URL, ['discount_max_percent' => '20', 'due_limit' => '5000'])->assertOk();

    auth()->forgetGuards();
    $this->actingAs($cashier, 'web');
    $quote()->assertOk()->assertJsonPath('blockers', [])->assertJsonPath('discount.max_percent', '20')->assertJsonPath('grand_total', '850.00');
});

describe('role matrix', function (): void {
    it('guards GET /settings/pos with settings.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(POS_SETTINGS_URL));
    })->with(RoleMatrix::dataset(Permission::SettingsView));

    it('guards PUT /settings/pos with settings.update', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson(POS_SETTINGS_URL, ['discount_max_percent' => '10', 'due_limit' => '5000']));
    })->with(RoleMatrix::dataset(Permission::SettingsUpdate));

    it('refuses a guest with 401', function (): void {
        $this->getJson(POS_SETTINGS_URL)->assertUnauthorized();
    });
});
