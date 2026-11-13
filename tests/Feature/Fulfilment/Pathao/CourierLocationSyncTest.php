<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Jobs\SyncCourierLocations;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\PathaoFixtures as PF;

/*
 * The location mirror (DATABASE-DESIGN §3.6) against real, trimmed sandbox lists. The sync
 * maps nothing (rule 32), is idempotent, keeps mappings across runs, and drops non-Bangladesh
 * data.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    PF::configure();
    config(['couriers.pathao.sync_delay_ms' => 0]);
});

/** @param  array<string, mixed>  $extraZones  synthetic zones appended to the real flat list */
function fakePathaoTree(array $extraZones = [], array $extraAreas = [], ?array $dropZoneIds = null): void
{
    $zones = PF::json('zones.flat');
    $zones['data'] = [...$zones['data'], ...$extraZones];
    if ($dropZoneIds !== null) {
        $zones['data'] = array_values(array_filter($zones['data'], static fn (array $z): bool => ! in_array($z['id'], $dropZoneIds, true)));
    }
    $areas = PF::json('areas.flat');
    $areas['data'] = [...$areas['data'], ...$extraAreas];

    Http::fake([
        PF::HOST.'/issue-token' => PF::response('token.issued'),
        PF::HOST.'/countries/1/city-list' => PF::response('cities'),
        PF::HOST.'/zones/list' => Http::response($zones),
        PF::HOST.'/areas/list' => Http::response($areas),
    ]);
}

function runPathaoSync(): App\Domain\Fulfilment\Locations\LocationSyncReport
{
    return app()->call([new SyncCourierLocations('pathao'), 'handle']);
}

function pathaoProvider(): CourierProvider
{
    return CourierProvider::query()->where('code', 'pathao')->firstOrFail();
}

it('mirrors cities, zones and areas from the flat lists', function (): void {
    fakePathaoTree();

    $report = runPathaoSync();

    expect($report->cities)->toBe(3)
        ->and($report->zones)->toBe(40)
        ->and($report->areas)->toBe(120)
        ->and($report->created)->toBe(160)
        ->and(CourierLocation::query()->count())->toBe(160);

    $zone = CourierLocation::query()->where('external_zone_id', '298')->whereNull('external_area_id')->firstOrFail();
    expect($zone->external_city_name)->toBe('Dhaka')
        ->and($zone->external_zone_name)->toBe('60 feet')
        ->and($zone->external_key)->toBe('1:298:-');
});

it('maps NOTHING, even where a zone name equals one of our upazila names', function (): void {
    // Synthetic: a Pathao zone literally named "Savar" in city 1 (Dhaka) — our district 47 has upazila Savar (365).
    fakePathaoTree([['id' => 99001, 'name' => 'Savar', 'originalName' => 'Savar', 'is_third_party' => 0, 'city_id' => 1]]);

    runPathaoSync();

    expect(CourierLocation::query()->where('is_mapped', true)->count())->toBe(0)
        ->and(CourierLocation::query()->whereNotNull('district_id')->count())->toBe(0)
        ->and(CourierLocation::query()->whereNotNull('upazila_id')->count())->toBe(0)
        ->and(CourierLocation::query()->where('external_zone_name', 'Savar')->exists())->toBeTrue();
});

it('is idempotent and never touches a human mapping', function (): void {
    fakePathaoTree();
    runPathaoSync();

    $zone = CourierLocation::query()->where('external_key', '1:298:-')->firstOrFail();
    $zone->forceFill(['district_id' => 47, 'upazila_id' => null, 'is_mapped' => true])->save();
    $snapshot = CourierLocation::query()->orderBy('id')->get(['id', 'external_key', 'external_zone_name', 'district_id', 'is_mapped', 'is_active'])->toArray();

    $again = runPathaoSync();

    expect($again->created)->toBe(0)
        ->and($again->deactivated)->toBe(0)
        ->and(CourierLocation::query()->orderBy('id')->get(['id', 'external_key', 'external_zone_name', 'district_id', 'is_mapped', 'is_active'])->toArray())->toBe($snapshot);
});

it('drops zones outside Bangladesh and their areas (Nepal: city 65 Kathmandu)', function (): void {
    fakePathaoTree(
        [['id' => 99065, 'name' => 'Thamel', 'originalName' => 'Thamel', 'is_third_party' => 0, 'city_id' => 65]],
        [['id' => 990651, 'name' => 'Thamel Chowk', 'originalName' => 'Thamel Chowk', 'is_third_party' => 0, 'zone_id' => 99065, 'is_home_delivery_available' => true, 'is_pickup_available' => true]],
    );

    runPathaoSync();

    expect(CourierLocation::query()->where('external_city_id', '65')->exists())->toBeFalse()
        ->and(CourierLocation::query()->where('external_zone_id', '99065')->exists())->toBeFalse();
});

it('deactivates — never deletes — rows the courier stops returning, keeping their mapping', function (): void {
    fakePathaoTree();
    runPathaoSync();
    CourierLocation::query()->where('external_key', '1:298:-')->update(['district_id' => 47, 'is_mapped' => true]);

    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    fakePathaoTree(dropZoneIds: [298]);
    $report = runPathaoSync();

    $gone = CourierLocation::query()->where('external_key', '1:298:-')->firstOrFail();
    expect($gone->is_active)->toBeFalse()
        ->and($gone->is_mapped)->toBeTrue()
        ->and($report->mappedInactive)->toBe(1)
        ->and($report->deactivated)->toBeGreaterThanOrEqual(1);
});

it('walks city → zone → area when the flat lists are unavailable', function (): void {
    $cities = PF::json('cities');
    $cities['data']['data'] = [['city_id' => 1, 'city_name' => 'Dhaka']];

    Http::fake([
        PF::HOST.'/issue-token' => PF::response('token.issued'),
        PF::HOST.'/countries/1/city-list' => Http::response($cities),
        PF::HOST.'/zones/list' => Http::response(['message' => 'Not Found', 'type' => 'error', 'code' => 404], 404),
        PF::HOST.'/areas/list' => Http::response(['message' => 'Not Found', 'type' => 'error', 'code' => 404], 404),
        PF::HOST.'/cities/1/zone-list' => PF::response('zones.city-1'),
        PF::HOST.'/zones/298/area-list' => PF::response('areas.zone-298'),
        PF::HOST.'/zones/*/area-list' => Http::response(['message' => 'Area list fetched.', 'type' => 'success', 'code' => 200, 'data' => ['data' => []]]),
    ]);

    $report = runPathaoSync();

    expect($report->zones)->toBe(12)
        ->and($report->areas)->toBe(1)
        ->and(CourierLocation::query()->where('external_area_id', '3069')->value('external_area_name'))->toBe('60 feet road');
});

it('refuses to apply an empty tree (it would deactivate every row)', function (): void {
    $cities = PF::json('cities');
    Http::fake([
        PF::HOST.'/issue-token' => PF::response('token.issued'),
        PF::HOST.'/countries/1/city-list' => Http::response($cities),
        PF::HOST.'/zones/list' => Http::response(['message' => 'Zone list fetched.', 'type' => 'success', 'code' => 200, 'data' => []]),
    ]);

    runPathaoSync();
})->throws(App\Domain\Fulfilment\Drivers\Pathao\PathaoException::class, 'zone list');

it('prints the counts from the artisan command', function (): void {
    fakePathaoTree();

    $this->artisan('toymart:couriers:sync-locations', ['provider' => 'pathao'])
        ->expectsOutputToContain('pathao: 3 cities, 40 zones, 120 areas')
        ->assertSuccessful();
});

it('queues the sync on the courier queue', function (): void {
    Illuminate\Support\Facades\Queue::fake();

    $this->artisan('toymart:couriers:sync-locations', ['provider' => 'pathao', '--queue' => true])->assertSuccessful();

    Illuminate\Support\Facades\Queue::assertPushedOn('courier', SyncCourierLocations::class);
});
