<?php

declare(strict_types=1);

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Locations\CourierLocationResolver;
use App\Domain\Fulfilment\Locations\CourierLocationSuggester;
use App\Domain\Fulfilment\Locations\LocationResolution;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;

/*
 * (provider, district, upazila, area?) → courier ids. Only human-mapped rows count; nothing
 * is matched by name against the courier's tree (rule 32). Geography: Dhaka 47 (Savar 365,
 * Dhamrai 366), Comilla 1 (Comilla Sadar 11).
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->pathao = CourierProvider::query()->where('code', 'pathao')->firstOrFail();
});

function courierRow(CourierProvider $p, string $city, string $cityName, string $zone, string $zoneName, ?string $area = null, ?string $areaName = null, array $mapping = []): CourierLocation
{
    return CourierLocation::query()->create([
        'provider_id' => $p->id,
        'external_key' => CourierLocation::keyFor($city, $zone, $area),
        'external_city_id' => $city, 'external_city_name' => $cityName,
        'external_zone_id' => $zone, 'external_zone_name' => $zoneName,
        'external_area_id' => $area, 'external_area_name' => $areaName,
        'is_active' => true,
        ...$mapping,
    ]);
}

it('needs no mapping for a free-text courier (Steadfast)', function (): void {
    $steadfast = CourierProvider::query()->where('code', 'steadfast')->firstOrFail();

    $r = app(CourierLocationResolver::class)->resolve($steadfast, 47, 365);

    expect($r->status)->toBe(LocationResolution::NOT_REQUIRED)->and($r->canBook())->toBeTrue()->and($r->ref)->toBeNull();
});

it('is unmapped — booking blocked — when nothing is mapped, even with an identically named zone', function (): void {
    courierRow($this->pathao, '1', 'Dhaka', '99001', 'Savar');

    $r = app(CourierLocationResolver::class)->resolve($this->pathao, 47, 365, 'Savar');

    expect($r->status)->toBe(LocationResolution::UNMAPPED)->and($r->canBook())->toBeFalse();
});

it('resolves the single mapped zone', function (): void {
    courierRow($this->pathao, '1', 'Dhaka', '1538', 'Ashulia Bazar', mapping: ['district_id' => 47, 'upazila_id' => 365, 'is_mapped' => true]);

    $r = app(CourierLocationResolver::class)->resolve($this->pathao, 47, 365);

    expect($r->status)->toBe(LocationResolution::RESOLVED)
        ->and([$r->ref?->externalCityId, $r->ref?->externalZoneId, $r->ref?->externalAreaId])->toBe(['1', '1538', null]);
});

it('never falls back from an upazila to a district-level mapping', function (): void {
    courierRow($this->pathao, '1', 'Dhaka', '30', 'Badda', mapping: ['district_id' => 47, 'upazila_id' => null, 'is_mapped' => true]);

    expect(app(CourierLocationResolver::class)->resolve($this->pathao, 47, 366)->status)->toBe(LocationResolution::UNMAPPED)
        ->and(app(CourierLocationResolver::class)->resolve($this->pathao, 47, null)->status)->toBe(LocationResolution::RESOLVED);
});

it('is ambiguous across several mapped zones, and the area text only picks an exact one', function (): void {
    $map = ['district_id' => 47, 'upazila_id' => null, 'is_mapped' => true];
    courierRow($this->pathao, '1', 'Dhaka', '30', 'Badda', mapping: $map);
    courierRow($this->pathao, '1', 'Dhaka', '1', 'Banani', mapping: $map);

    $resolver = app(CourierLocationResolver::class);
    $ambiguous = $resolver->resolve($this->pathao, 47, null, 'Banani Road 11');

    expect($ambiguous->status)->toBe(LocationResolution::AMBIGUOUS)
        ->and($ambiguous->candidates)->toHaveCount(2)
        ->and($resolver->resolve($this->pathao, 47, null, ' banani ')->ref?->externalZoneId)->toBe('1');
});

it('ignores inactive mappings', function (): void {
    courierRow($this->pathao, '1', 'Dhaka', '1538', 'Ashulia Bazar', mapping: ['district_id' => 47, 'upazila_id' => 365, 'is_mapped' => true, 'is_active' => false]);

    expect(app(CourierLocationResolver::class)->resolve($this->pathao, 47, 365)->status)->toBe(LocationResolution::UNMAPPED);
});

it('suggests Cumilla for Comilla but writes nothing', function (): void {
    courierRow($this->pathao, '13', 'Cumilla', '777', 'Cumilla Sadar');
    courierRow($this->pathao, '1', 'Dhaka', '30', 'Badda');

    $suggestions = app(CourierLocationSuggester::class)->zonesFor($this->pathao, District::query()->findOrFail(1), Upazila::query()->findOrFail(11));

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['location']->external_zone_name)->toBe('Cumilla Sadar')
        ->and(CourierLocation::query()->where('is_mapped', true)->count())->toBe(0);
});
