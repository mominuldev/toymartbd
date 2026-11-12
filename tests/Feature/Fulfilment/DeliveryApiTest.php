<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\District;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Models\User;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Carbon;

/*
 * Storefront delivery endpoints (P4-STO-06): `storefront:read` token, every figure
 * from DeliveryChargeResolver.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    // Monday 10:00 Dhaka.
    Carbon::setTestNow('2026-11-16 04:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('needs the storefront token and ability', function (): void {
    $this->getJson('/api/v1/storefront/delivery-quote?district_id=47')->assertUnauthorized();

    $weak = User::factory()->create()->createToken('other', ['other'])->plainTextToken;
    $this->withToken($weak)->getJson('/api/v1/storefront/delivery-quote?district_id=47')->assertForbidden();
    $this->withToken($weak)->getJson('/api/v1/storefront/districts')->assertForbidden();
});

it('quotes a district, variant and quantity', function (): void {
    $variant = ProductVariant::factory()->create(['price' => '1225.00', 'weight_grams' => 700]);

    $response = $this->withToken($this->token)
        ->getJson("/api/v1/storefront/delivery-quote?district_id=8&variant_id={$variant->id}&quantity=2")
        ->assertOk();

    expect($response->json())->toBe([
        'serviceable' => true,
        'zone' => 'outside_dhaka',
        'district' => ['id' => 8, 'name' => 'Chattogram', 'name_bn' => District::query()->find(8)?->name_bn],
        'upazila' => null,
        'upazila_required' => false,
        'courier' => ['code' => 'steadfast', 'name' => 'Steadfast', 'name_bn' => 'স্টেডফাস্ট'],
        'subtotal' => '2450.00',
        'weight_grams' => 1400,
        'base_charge' => '130.00',
        'weight_surcharge' => '20.00',
        'charge' => '150.00',
        'standard_charge' => '150.00',
        'free_shipping' => ['applied' => false, 'reason' => null, 'threshold' => '3000.00', 'remaining' => '550.00', 'progress_percent' => 81],
        'eta' => [
            'dispatch_date' => '2026-11-16', 'earliest' => '2026-11-19', 'latest' => '2026-11-21',
            'min_days' => 3, 'max_days' => 4, 'cutoff_time' => '17:00', 'cutoff_passed' => false, 'timezone' => 'Asia/Dhaka',
        ],
        'cod_advance' => null,
    ]);
});

it('returns exactly what the resolver returns — the checkout code path', function (): void {
    $variant = ProductVariant::factory()->create(['price' => '890.00', 'weight_grams' => 150]);

    $api = $this->withToken($this->token)
        ->getJson("/api/v1/storefront/delivery-quote?district_id=47&upazila_id=365&variant_id={$variant->id}")
        ->assertOk()->json();

    $direct = app(DeliveryChargeResolver::class)->quote(District::query()->findOrFail(47), App\Domain\Customer\Models\Upazila::query()->findOrFail(365), new DeliveryParcel([$variant->id => 1]));

    expect($api['charge'])->toBe($direct->charge?->toDecimal())
        ->and($api['zone'])->toBe($direct->zone?->value)
        ->and($api['eta']['earliest'])->toBe($direct->eta?->earliest->toDateString());
});

it('marks a not-serviced upazila and still answers 200', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/storefront/delivery-quote?district_id=4&upazila_id=37')
        ->assertOk()
        ->assertJsonPath('serviceable', false)
        ->assertJsonPath('zone', null)
        ->assertJsonPath('charge', null)
        ->assertJsonPath('upazila.name', 'Barkal');
});

it('validates the query', function (string $query, string $field): void {
    $archived = ProductVariant::factory()->create(['status' => VariantStatus::Archived]);
    $query = str_replace('{archived}', (string) $archived->id, $query);

    $this->withToken($this->token)->getJson("/api/v1/storefront/delivery-quote?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', $field);
})->with([
    'missing district' => ['', 'district_id'],
    'unknown district' => ['district_id=999', 'district_id'],
    'upazila of another district' => ['district_id=8&upazila_id=365', 'upazila_id'],
    'archived variant' => ['district_id=8&variant_id={archived}', 'variant_id'],
    'quantity zero' => ['district_id=8&quantity=0', 'quantity'],
    'quantity too large' => ['district_id=8&quantity=100', 'quantity'],
]);

it('answers 503 when no courier provider is active', function (): void {
    CourierProvider::query()->update(['is_active' => false]);

    $this->withToken($this->token)->getJson('/api/v1/storefront/delivery-quote?district_id=47')->assertStatus(503);
});

it('publishes the rate table', function (): void {
    $this->withToken($this->token)->getJson('/api/v1/storefront/delivery-rates')
        ->assertOk()
        ->assertExactJson([
            'courier' => ['code' => 'steadfast', 'name' => 'Steadfast', 'name_bn' => 'স্টেডফাস্ট'],
            'zones' => [
                ['zone' => 'inside_dhaka_metro', 'charge' => '70.00', 'min_days' => 1, 'max_days' => 2],
                ['zone' => 'dhaka_suburb', 'charge' => '100.00', 'min_days' => 2, 'max_days' => 3],
                ['zone' => 'outside_dhaka', 'charge' => '130.00', 'min_days' => 3, 'max_days' => 4],
            ],
            'free_shipping_threshold' => '3000.00',
            'included_weight_grams' => 1000,
            'per_kg_charge' => '20.00',
            'cutoff_time' => '17:00',
            'timezone' => 'Asia/Dhaka',
        ]);
});

it('lists 64 districts, Dhaka division first, with the upazila flags', function (): void {
    $data = $this->withToken($this->token)->getJson('/api/v1/storefront/districts')->assertOk()->json('data');

    $byId = collect($data)->keyBy('id');

    expect($data)->toHaveCount(64)
        ->and(collect($data)->take(13)->pluck('division.name')->unique()->values()->all())->toBe(['Dhaka'])
        ->and($data[0]['name'])->toBe('Dhaka')
        ->and($byId[47])->toMatchArray(['upazila_required' => true, 'city_option' => true])
        ->and($byId[4])->toMatchArray(['upazila_required' => true, 'city_option' => false])
        ->and($byId[41])->toMatchArray(['upazila_required' => false, 'city_option' => false])
        ->and(array_keys($data[0]))->toBe(['id', 'name', 'name_bn', 'division', 'upazila_required', 'city_option']);
});

it('lists a district\'s upazilas with serviceability', function (): void {
    $data = $this->withToken($this->token)->getJson('/api/v1/storefront/districts/4/upazilas')->assertOk()->json('data');

    expect(collect($data)->firstWhere('name', 'Barkal')['serviceable'])->toBeFalse()
        ->and(collect($data)->firstWhere('name', 'Rangamati Sadar')['serviceable'])->toBeTrue()
        ->and(collect($data)->pluck('name')->all())->toBe(collect($data)->pluck('name')->sort()->values()->all());

    $this->withToken($this->token)->getJson('/api/v1/storefront/districts/999/upazilas')->assertNotFound();
});
