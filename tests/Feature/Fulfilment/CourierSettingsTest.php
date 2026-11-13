<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\District;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\RoleMatrix;

/*
 * Settings → Courier settings & delivery charges (P6). Credentials are write-only (rule 20),
 * rate cards feed DeliveryChargeResolver, and the role matrix holds (rule 21).
 */

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    (new DeliverySeeder)->run();
});

function courier(string $code): CourierProvider
{
    return CourierProvider::query()->where('code', $code)->firstOrFail();
}

function courierAdmin(Role $role = Role::SuperAdmin): User
{
    return User::factory()->withRole($role)->create()->refresh();
}

/** @return array<string, mixed> */
function rateCard(array $overrides = []): array
{
    return array_merge([
        'base_charge_dhaka' => '60.00', 'base_charge_suburb' => '110.00', 'base_charge_outside' => '140.00',
        'included_weight_grams' => 1000, 'per_kg_charge' => '20.00', 'cod_fee_percent' => '1.00', 'return_charge' => '70.00',
    ], $overrides);
}

it('lets manage_rates or manage_integrations open the screen, nobody else', function (Role $role): void {
    $allowed = RoleMatrix::allows($role, Permission::ShipmentsManageRates) || RoleMatrix::allows($role, Permission::SettingsManageIntegrations);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/settings/couriers'));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/settings/delivery'));
})->with(array_combine(Role::values(), array_map(fn (Role $r): array => [$r], Role::cases())));

it('guards rate card, on/off and threshold writes with shipments.manage_rates', function (Role $role, bool $allowed): void {
    $steadfast = courier('steadfast');
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/rates", rateCard()));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->patchJson('/api/v1/admin/settings/couriers/'.courier('pathao')->id, ['is_active' => true]));
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => '2500.00']));
})->with(RoleMatrix::dataset(Permission::ShipmentsManageRates));

it('guards credential writes with settings.manage_integrations — a manager cannot', function (Role $role, bool $allowed): void {
    $steadfast = courier('steadfast');
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/credentials", [
        'credentials' => ['api_key' => 'sf-key-0000111122223333'],
    ]));
})->with(RoleMatrix::dataset(Permission::SettingsManageIntegrations));

it('never returns a credential value, only set flags and a four-character hint', function (): void {
    $pathao = courier('pathao');
    $this->actingAs(courierAdmin())->putJson("/api/v1/admin/settings/couriers/{$pathao->id}/credentials", [
        'credentials' => [
            'client_id' => 'pathao-client-7781', 'client_secret' => 'super-secret-client-value-9QzX',
            'username' => 'merchant@toymartbd.com', 'password' => 'Hunter2-portal-pass', 'store_id' => '4412',
        ],
        'webhook_secret' => 'whsec-0123456789abcdef',
    ])->assertOk()->assertJsonPath('trust', 'unverified')->assertJsonPath('credentials_complete', true);

    $response = $this->getJson('/api/v1/admin/settings/couriers')->assertOk();
    $raw = $response->getContent();
    foreach (['pathao-client-7781', 'super-secret-client-value-9QzX', 'merchant@toymartbd.com', 'Hunter2-portal-pass', 'whsec-0123456789abcdef'] as $secret) {
        expect($raw)->not->toContain($secret);
    }
    $row = collect($response->json('data'))->firstWhere('code', 'pathao');
    $fields = collect($row['credential_fields'])->keyBy('key');
    expect($fields['client_secret'])->toMatchArray(['set' => true, 'secret' => true, 'hint' => '••••9QzX'])
        ->and($fields['store_id'])->toMatchArray(['set' => true, 'hint' => '••••'])
        ->and($row['webhook_secret_set'])->toBeTrue()
        ->and($row['credentials_updated_at'])->not->toBeNull();

    // Stored encrypted: the raw column never holds the plain value.
    $column = DB::table('courier_providers')->where('id', $pathao->id)->value('credentials');
    expect($column)->not->toContain('Hunter2-portal-pass');
    expect($pathao->refresh()->credentials['password'])->toBe('Hunter2-portal-pass');
});

it('keeps a credential when its field is sent blank, clears it on request, and audits key names only', function (): void {
    $steadfast = courier('steadfast');
    $admin = courierAdmin();
    $this->actingAs($admin)->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/credentials", [
        'credentials' => ['api_key' => 'sf-api-key-AAAA1111', 'secret_key' => 'sf-secret-BBBB2222'],
    ])->assertOk()->assertJsonPath('trust', 'unverified');

    $this->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/credentials", [
        'credentials' => ['api_key' => '', 'secret_key' => '   '],
    ])->assertOk();
    expect($steadfast->refresh()->credentials)->toBe(['api_key' => 'sf-api-key-AAAA1111', 'secret_key' => 'sf-secret-BBBB2222']);

    $this->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/credentials", ['clear' => ['secret_key']])
        ->assertOk()->assertJsonPath('trust', 'not_connected');
    expect($steadfast->refresh()->credentials)->toBe(['api_key' => 'sf-api-key-AAAA1111']);

    $audits = AuditSensitive::query()->where('action', AuditAction::CredentialChanged)->orderBy('id')->get();
    expect($audits)->toHaveCount(2)
        ->and($audits[0]->after)->toBe(['provider' => 'steadfast', 'changed' => ['api_key', 'secret_key']])
        ->and($audits[0]->user_id)->toBe($admin->id)
        ->and(json_encode($audits->toArray()))->not->toContain('sf-api-key-AAAA1111');
});

it('rejects credential keys the provider does not use and value+clear together', function (): void {
    $steadfast = courier('steadfast');
    $this->actingAs(courierAdmin())->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/credentials", [
        'credentials' => ['password' => 'portal-password'],
    ])->assertStatus(422);
    $this->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/credentials", [
        'credentials' => ['api_key' => 'sf-api-key-AAAA1111'], 'clear' => ['api_key'],
    ])->assertStatus(422)->assertJsonPath('errors.0.field', 'credentials.api_key');
});

it('validates the rate card: decimal strings, non-negative, weight in 100 g steps', function (array $bad, string $field): void {
    $steadfast = courier('steadfast');
    $this->actingAs(courierAdmin())->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/rates", rateCard($bad))
        ->assertStatus(422)->assertJsonPath('errors.0.field', $field);
})->with([
    'negative charge' => [['base_charge_dhaka' => '-10.00'], 'base_charge_dhaka'],
    'float with 3 places' => [['per_kg_charge' => '20.555'], 'per_kg_charge'],
    'missing outside' => [['base_charge_outside' => null], 'base_charge_outside'],
    'odd grams' => [['included_weight_grams' => 1050], 'included_weight_grams'],
    'too heavy' => [['included_weight_grams' => 40000], 'included_weight_grams'],
    'percent over 99.99' => [['cod_fee_percent' => '120'], 'cod_fee_percent'],
]);

it('saves a rate card the delivery-charge resolver then quotes from (Steadfast 1 kg step)', function (): void {
    $steadfast = courier('steadfast');
    $this->actingAs(courierAdmin())->putJson("/api/v1/admin/settings/couriers/{$steadfast->id}/rates", rateCard())
        ->assertOk()
        ->assertJsonPath('rates.base_charge_outside', '140.00')
        ->assertJsonPath('rates.included_weight_grams', 1000)
        ->assertJsonPath('rates.cod_fee_percent', '1.00');
    expect($steadfast->refresh()->rate_card_updated_at)->not->toBeNull();

    $this->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => null])
        ->assertOk()->assertJsonPath('free_shipping_threshold', null);

    $this->seed(GeographySeeder::class);
    $variant = ProductVariant::factory()->create(['price' => '900.00', 'weight_grams' => 2300]);
    // Chattogram (outside), 2,300 g with a 1,000 g allowance → 2 started kg over → 140 + 2 × 20.
    $quote = app(DeliveryChargeResolver::class)->quote(District::query()->findOrFail(8), null, new DeliveryParcel([$variant->id => 1]));
    expect($quote->baseCharge?->toDecimal())->toBe('140.00')
        ->and($quote->weightSurcharge?->toDecimal())->toBe('40.00')
        ->and($quote->charge?->toDecimal())->toBe('180.00');

    // Pathao's 2 kg step on the same parcel, once it is the default: 1 started kg over.
    $pathao = courier('pathao');
    $this->putJson("/api/v1/admin/settings/couriers/{$pathao->id}/rates", rateCard(['included_weight_grams' => 2000, 'per_kg_charge' => '15.00']))->assertOk();
    $this->patchJson("/api/v1/admin/settings/couriers/{$pathao->id}", ['is_default' => true])->assertOk();
    $quote = app(DeliveryChargeResolver::class)->quote(District::query()->findOrFail(8), null, new DeliveryParcel([$variant->id => 1]));
    expect($quote->courier?->code)->toBe('pathao')->and($quote->charge?->toDecimal())->toBe('155.00');
});

it('sets the free-delivery threshold and refuses zero', function (): void {
    $this->actingAs(courierAdmin())->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => '2500.00'])
        ->assertOk()->assertJsonPath('free_shipping_threshold', '2500.00');
    $this->getJson('/api/v1/admin/settings/delivery')->assertJsonPath('free_shipping_threshold', '2500.00');
    $this->putJson('/api/v1/admin/settings/delivery', ['free_shipping_threshold' => '0'])->assertStatus(422);
});

it('keeps exactly one default and never switches off the default or the last active courier', function (): void {
    $steadfast = courier('steadfast');
    $pathao = courier('pathao');
    $this->actingAs(courierAdmin());

    $this->patchJson("/api/v1/admin/settings/couriers/{$steadfast->id}", ['is_active' => false])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'is_active');

    $this->patchJson("/api/v1/admin/settings/couriers/{$pathao->id}", ['is_default' => true])
        ->assertOk()->assertJsonPath('is_default', true)->assertJsonPath('is_active', true);
    expect($steadfast->refresh()->is_default)->toBeFalse()
        ->and(CourierProvider::query()->where('is_default', true)->count())->toBe(1)
        ->and(CourierProvider::defaultForQuotes()?->code)->toBe('pathao');

    $this->patchJson("/api/v1/admin/settings/couriers/{$steadfast->id}", ['is_active' => false])->assertOk();
    $this->patchJson("/api/v1/admin/settings/couriers/{$pathao->id}", ['is_active' => false])->assertStatus(422);
});

it('reports trust per provider and never lets this screen mark a provider verified', function (): void {
    $steadfast = courier('steadfast');
    $this->actingAs(courierAdmin())->getJson('/api/v1/admin/settings/couriers')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'steadfast')
        ->assertJsonPath('data.0.trust', 'not_connected')
        ->assertJsonPath('data.0.can.update_credentials', true);

    $this->patchJson("/api/v1/admin/settings/couriers/{$steadfast->id}", ['verified' => true])->assertOk();
    expect($steadfast->refresh()->verified)->toBeFalse();
});

it('tells a manager they may edit rates but not credentials', function (): void {
    $this->actingAs(courierAdmin(Role::Manager))->getJson('/api/v1/admin/settings/couriers')
        ->assertOk()
        ->assertJsonPath('data.0.can.update_rates', true)
        ->assertJsonPath('data.0.can.update_credentials', false);
});
