<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Actions\UpdateDeliveryAreaSettings;
use App\Domain\Fulfilment\DTOs\DeliveryAreaSettings;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Events\DeliveryReferenceDataChanged;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Domain\Ordering\Models\Order;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Support\RoleMatrix;

/*
 * Store settings › Delivery areas & timing — GET/PUT /admin/settings/delivery-areas.
 * The editor writes exactly the `delivery.*` keys DeliverySettings reads, so a save changes
 * the zone, ETA, serviceability and COD advance of the next quote and checkout (rule 1).
 */

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    // Monday 10:00 Dhaka.
    Carbon::setTestNow('2026-11-16 04:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/** The seeded document as the PUT body, with overrides merged one level deep. */
function deliveryAreasBody(array $overrides = []): array
{
    $body = [
        'cutoff_time' => '17:00',
        'eta_days' => [
            'inside_dhaka_metro' => ['min' => 1, 'max' => 2],
            'dhaka_suburb' => ['min' => 2, 'max' => 3],
            'outside_dhaka' => ['min' => 3, 'max' => 4],
        ],
        'metro_district_ids' => [47],
        'suburb_district_ids' => [40, 41, 43, 46, 48],
        'not_serviced_upazila_ids' => [37, 40, 41, 91, 103, 241],
        'cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka'], 'threshold' => '5000.00', 'amount' => '200.00'],
    ];

    return array_replace($body, $overrides);
}

function deliveryAreasOwner(): User
{
    return User::factory()->withRole(Role::SuperAdmin)->create()->refresh();
}

function storefrontQuote(string $query): Illuminate\Testing\TestResponse
{
    $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    auth()->forgetGuards();

    return test()->withToken($token)->getJson('/api/v1/storefront/delivery-quote?'.$query)->assertOk();
}

it('lets settings.view read the delivery areas, nobody else (rule 21)', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/settings/delivery-areas'));
})->with(RoleMatrix::dataset(Permission::SettingsView));

it('needs settings.update AND shipments.manage_rates to save (rule 21)', function (Role $role): void {
    $allowed = RoleMatrix::allows($role, Permission::SettingsUpdate) && RoleMatrix::allows($role, Permission::ShipmentsManageRates);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody(['cutoff_time' => '16:30'])));
})->with(array_combine(Role::values(), array_map(static fn (Role $role): array => [$role], Role::cases())));

it('refuses a settings.update holder without shipments.manage_rates', function (): void {
    $user = User::factory()->create()->refresh();
    $user->givePermissionTo([Permission::SettingsView->value, Permission::SettingsUpdate->value]);

    RoleMatrix::assertForbidden($this->actingAs($user)->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody()));
    $this->getJson('/api/v1/admin/settings/delivery-areas')->assertOk();
});

it('reads the seeded values the resolver uses', function (): void {
    $this->actingAs(deliveryAreasOwner())->getJson('/api/v1/admin/settings/delivery-areas')
        ->assertOk()
        ->assertExactJson([
            'cutoff_time' => '17:00',
            'timezone' => 'Asia/Dhaka',
            'eta_days' => [
                'inside_dhaka_metro' => ['min' => 1, 'max' => 2],
                'dhaka_suburb' => ['min' => 2, 'max' => 3],
                'outside_dhaka' => ['min' => 3, 'max' => 4],
            ],
            'metro_district_ids' => [47],
            'suburb_district_ids' => [40, 41, 43, 46, 48],
            'not_serviced_upazila_ids' => [37, 40, 41, 91, 103, 241],
            'cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka'], 'threshold' => '5000.00', 'amount' => '200.00'],
        ]);
});

it('round-trips a save into the delivery.* settings DeliverySettings reads', function (): void {
    $this->actingAs(deliveryAreasOwner());
    $body = deliveryAreasBody([
        'cutoff_time' => '15:30',
        'eta_days' => [
            'inside_dhaka_metro' => ['min' => 0, 'max' => 1],
            'dhaka_suburb' => ['min' => 1, 'max' => 2],
            'outside_dhaka' => ['min' => 2, 'max' => 5],
        ],
        'metro_district_ids' => [47],
        'suburb_district_ids' => [48, 41],
        'not_serviced_upazila_ids' => [241, 37],
        'cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka', 'dhaka_suburb'], 'threshold' => '4000', 'amount' => '150.5'],
    ]);

    $this->putJson('/api/v1/admin/settings/delivery-areas', $body)->assertOk()
        ->assertJsonPath('cutoff_time', '15:30')
        ->assertJsonPath('suburb_district_ids', [41, 48])
        ->assertJsonPath('not_serviced_upazila_ids', [37, 241])
        // Zones in DeliveryZone order; money normalised to DECIMAL(14,2) strings (rule 6).
        ->assertJsonPath('cod_advance', ['enabled' => true, 'zones' => ['dhaka_suburb', 'outside_dhaka'], 'threshold' => '4000.00', 'amount' => '150.50']);

    $this->getJson('/api/v1/admin/settings/delivery-areas')->assertJsonPath('eta_days.inside_dhaka_metro', ['min' => 0, 'max' => 1]);

    expect(Setting::get('delivery.cutoff_time'))->toBe('15:30')
        ->and(Setting::get('delivery.eta_days'))->toBe(['inside_dhaka_metro' => [0, 1], 'dhaka_suburb' => [1, 2], 'outside_dhaka' => [2, 5]])
        ->and(Setting::get('delivery.cod_advance'))->toBe(['zones' => ['dhaka_suburb', 'outside_dhaka'], 'threshold' => '4000.00', 'amount' => '150.50']);

    $settings = new DeliverySettings;
    expect($settings->cutoff())->toBe([15, 30])
        ->and($settings->etaDays(DeliveryZone::OutsideDhaka))->toBe([2, 5])
        ->and($settings->suburbDistrictIds())->toBe([41, 48])
        ->and($settings->notServicedUpazilaIds())->toBe([37, 241])
        ->and($settings->codAdvance()['amount']->toDecimal())->toBe('150.50');
});

it('switches the COD advance off by storing null, and keeps it off', function (): void {
    $this->actingAs(deliveryAreasOwner())
        ->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody(['cod_advance' => ['enabled' => false]]))
        ->assertOk()
        ->assertJsonPath('cod_advance', ['enabled' => false, 'zones' => [], 'threshold' => null, 'amount' => null]);

    expect(Setting::query()->find('delivery.cod_advance')?->value)->toBeNull()
        ->and((new DeliverySettings)->codAdvance())->toBeNull();
    $this->getJson('/api/v1/admin/settings/checkout')->assertJsonPath('cod_advance', null);
});

it('validates the document', function (array $override, string $field): void {
    $this->actingAs(deliveryAreasOwner())
        ->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody($override))
        ->assertUnprocessable()
        ->assertJsonFragment(['field' => $field]);
})->with([
    'cut-off not a time' => [['cutoff_time' => '5pm'], 'cutoff_time'],
    'cut-off out of range' => [['cutoff_time' => '24:00'], 'cutoff_time'],
    'eta max below min' => [['eta_days' => ['inside_dhaka_metro' => ['min' => 3, 'max' => 2], 'dhaka_suburb' => ['min' => 2, 'max' => 3], 'outside_dhaka' => ['min' => 3, 'max' => 4]]], 'eta_days.inside_dhaka_metro.max'],
    'eta too long' => [['eta_days' => ['inside_dhaka_metro' => ['min' => 1, 'max' => 2], 'dhaka_suburb' => ['min' => 2, 'max' => 3], 'outside_dhaka' => ['min' => 3, 'max' => 31]]], 'eta_days.outside_dhaka.max'],
    'eta negative' => [['eta_days' => ['inside_dhaka_metro' => ['min' => -1, 'max' => 2], 'dhaka_suburb' => ['min' => 2, 'max' => 3], 'outside_dhaka' => ['min' => 3, 'max' => 4]]], 'eta_days.inside_dhaka_metro.min'],
    'eta zone missing' => [['eta_days' => ['inside_dhaka_metro' => ['min' => 1, 'max' => 2], 'dhaka_suburb' => ['min' => 2, 'max' => 3]]], 'eta_days.outside_dhaka'],
    'eta unknown zone' => [['eta_days' => ['inside_dhaka_metro' => ['min' => 1, 'max' => 2], 'dhaka_suburb' => ['min' => 2, 'max' => 3], 'outside_dhaka' => ['min' => 3, 'max' => 4], 'mars' => ['min' => 1, 'max' => 1]]], 'eta_days'],
    'unknown metro district' => [['metro_district_ids' => [999]], 'metro_district_ids.0'],
    'duplicate suburb district' => [['suburb_district_ids' => [41, 41]], 'suburb_district_ids.0'],
    'district both metro and suburb' => [['metro_district_ids' => [47], 'suburb_district_ids' => [41, 47]], 'suburb_district_ids.1'],
    'unknown upazila' => [['not_serviced_upazila_ids' => [99999]], 'not_serviced_upazila_ids.0'],
    'districts missing' => [['metro_district_ids' => null], 'metro_district_ids'],
    'advance zone unknown' => [['cod_advance' => ['enabled' => true, 'zones' => ['mars'], 'threshold' => '5000.00', 'amount' => '200.00']], 'cod_advance.zones.0'],
    'advance without zones' => [['cod_advance' => ['enabled' => true, 'zones' => [], 'threshold' => '5000.00', 'amount' => '200.00']], 'cod_advance.zones'],
    'advance threshold zero' => [['cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka'], 'threshold' => '0.00', 'amount' => '200.00']], 'cod_advance.threshold'],
    'advance amount a float-looking string' => [['cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka'], 'threshold' => '5000.00', 'amount' => '2e2']], 'cod_advance.amount'],
    'advance amount missing' => [['cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka'], 'threshold' => '5000.00']], 'cod_advance.amount'],
    'advance not below threshold' => [['cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka'], 'threshold' => '500.00', 'amount' => '500.00']], 'cod_advance.amount'],
    'advance enabled missing' => [['cod_advance' => ['zones' => ['outside_dhaka'], 'threshold' => '5000.00', 'amount' => '200.00']], 'cod_advance.enabled'],
]);

it('rejects an invalid save without writing anything', function (): void {
    $this->actingAs(deliveryAreasOwner())
        ->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody(['cutoff_time' => '12:00', 'metro_district_ids' => [999]]))
        ->assertUnprocessable();

    expect((new DeliverySettings)->cutoffTime())->toBe('17:00');
});

it('changes the zone of the next storefront quote: a district made suburb, then not', function (): void {
    $variant = ProductVariant::factory()->create(['price' => '1000.00', 'weight_grams' => 500]);
    storefrontQuote("district_id=8&variant_id={$variant->id}")->assertJsonPath('zone', 'outside_dhaka')->assertJsonPath('charge', '130.00');

    $this->actingAs(deliveryAreasOwner())->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody(['suburb_district_ids' => [8, 40, 41, 43, 46, 48]]))->assertOk();

    storefrontQuote("district_id=8&variant_id={$variant->id}")
        ->assertJsonPath('zone', 'dhaka_suburb')
        ->assertJsonPath('charge', '100.00')
        ->assertJsonPath('eta.min_days', 2)
        ->assertJsonPath('eta.max_days', 3);

    expect(app(DeliveryChargeResolver::class)->zoneFor(District::query()->findOrFail(8), null))->toBe(DeliveryZone::DhakaSuburb);
});

it('makes a district-level address metro only through the metro list', function (): void {
    $resolver = static fn (): DeliveryChargeResolver => app(DeliveryChargeResolver::class);
    expect($resolver()->zoneFor(District::query()->findOrFail(47), null))->toBe(DeliveryZone::InsideDhakaMetro);

    $this->actingAs(deliveryAreasOwner())->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody(['metro_district_ids' => []]))->assertOk();

    expect($resolver()->zoneFor(District::query()->findOrFail(47), null))->toBe(DeliveryZone::OutsideDhaka);
});

it('changes serviceability: an upazila marked not serviced stops quoting; one released quotes again', function (): void {
    $rangunia = Upazila::query()->where('district_id', 8)->firstOrFail();
    storefrontQuote("district_id=8&upazila_id={$rangunia->id}")->assertJsonPath('serviceable', true);
    storefrontQuote('district_id=4&upazila_id=37')->assertJsonPath('serviceable', false);

    $this->actingAs(deliveryAreasOwner())->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody([
        'not_serviced_upazila_ids' => [$rangunia->id, 40, 41, 91, 103, 241],
    ]))->assertOk();

    storefrontQuote("district_id=8&upazila_id={$rangunia->id}")->assertJsonPath('serviceable', false)->assertJsonPath('charge', null);
    storefrontQuote('district_id=4&upazila_id=37')->assertJsonPath('serviceable', true)->assertJsonPath('zone', 'outside_dhaka');
});

it('changes the ETA window and the Dhaka cut-off of the next quote', function (): void {
    storefrontQuote('district_id=8')
        ->assertJsonPath('eta.cutoff_passed', false)
        ->assertJsonPath('eta.dispatch_date', '2026-11-16');

    $this->actingAs(deliveryAreasOwner())->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody([
        // 10:00 Dhaka is after a 09:30 cut-off: dispatch moves to Tuesday.
        'cutoff_time' => '09:30',
        'eta_days' => [
            'inside_dhaka_metro' => ['min' => 1, 'max' => 2],
            'dhaka_suburb' => ['min' => 2, 'max' => 3],
            'outside_dhaka' => ['min' => 5, 'max' => 7],
        ],
    ]))->assertOk();

    storefrontQuote('district_id=8')
        ->assertJsonPath('eta.cutoff_time', '09:30')
        ->assertJsonPath('eta.cutoff_passed', true)
        ->assertJsonPath('eta.dispatch_date', '2026-11-17')
        ->assertJsonPath('eta.min_days', 5)
        ->assertJsonPath('eta.max_days', 7);
});

it('changes the COD-advance decision of the quote and of checkout', function (): void {
    config(['risk.first_order_hold_threshold' => '100000.00']);
    $variant = CartTestKit::variant('1000.00', 10);
    storefrontQuote("district_id=8&variant_id={$variant->id}")->assertJsonPath('cod_advance', null);

    $this->actingAs(deliveryAreasOwner())->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody([
        'cod_advance' => ['enabled' => true, 'zones' => ['outside_dhaka'], 'threshold' => '800.00', 'amount' => '150.00'],
    ]))->assertOk();

    storefrontQuote("district_id=8&variant_id={$variant->id}")->assertJsonPath('cod_advance', '150.00');

    // Checkout (CheckoutKit: Chattogram/Rangunia, outside Dhaka) reaches the same decision.
    $storefront = CartTestKit::storefrontToken();
    $cart = CheckoutKit::cart([$variant->id => 1]);
    auth()->forgetGuards();
    $this->withToken($storefront)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();

    expect(Order::query()->sole()->risk_flags['hold_reasons'])->toContain('cod_advance_required');
});

it('purges the storefront `delivery` tag after a save that changes something, and only then', function (): void {
    $this->actingAs(deliveryAreasOwner());
    Queue::fake();

    $this->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody())->assertOk();
    // Same set in another order is not a change either.
    $this->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody(['suburb_district_ids' => [48, 46, 43, 41, 40]]))->assertOk();
    Queue::assertNotPushed(RevalidateStorefrontTags::class);

    $this->putJson('/api/v1/admin/settings/delivery-areas', deliveryAreasBody(['cutoff_time' => '18:00']))->assertOk();
    Queue::assertPushedOn('index', RevalidateStorefrontTags::class, fn (RevalidateStorefrontTags $job): bool => $job->tags === ['delivery'] && $job->afterCommit === true);
    Queue::assertPushed(RevalidateStorefrontTags::class, 1);
});

it('writes only the changed keys and dispatches one after-commit event', function (): void {
    Event::fake([DeliveryReferenceDataChanged::class]);
    $before = Setting::query()->find('delivery.eta_days')?->updated_at;
    Carbon::setTestNow('2026-11-16 05:00:00');

    $current = DeliveryAreaSettings::fromSettings(new DeliverySettings);
    $changed = app(UpdateDeliveryAreaSettings::class)->handle(DeliveryAreaSettings::make(
        '16:00', $current->etaDays, $current->metroDistrictIds, $current->suburbDistrictIds, $current->notServicedUpazilaIds, $current->codAdvance,
    ), null);

    expect($changed)->toBe(['cutoff_time'])
        ->and(Setting::query()->find('delivery.eta_days')?->updated_at?->equalTo($before))->toBeTrue();
    Event::assertDispatchedTimes(DeliveryReferenceDataChanged::class, 1);
    Event::assertDispatched(DeliveryReferenceDataChanged::class, fn (DeliveryReferenceDataChanged $e): bool => $e->reason === 'delivery_areas');
});

it('drops the purge when the outer transaction rolls back', function (): void {
    Queue::fake();
    $current = DeliveryAreaSettings::fromSettings(new DeliverySettings);

    DB::beginTransaction();
    app(UpdateDeliveryAreaSettings::class)->handle(DeliveryAreaSettings::make(
        '11:00', $current->etaDays, $current->metroDistrictIds, $current->suburbDistrictIds, $current->notServicedUpazilaIds, $current->codAdvance,
    ), null);
    DB::rollBack();

    Queue::assertNotPushed(RevalidateStorefrontTags::class);
    expect((new DeliverySettings)->cutoffTime())->toBe('17:00');
});
