<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Contracts\FreeShippingPromotion;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Exceptions\DeliveryQuoteUnavailable;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;

/*
 * DATABASE-DESIGN §5.1 — the one delivery-charge implementation (P4-CRT-03).
 * Geography ids: Dhaka district 47 (Savar 365, Dhamrai 366, Keraniganj 367, Nawabganj 368,
 * Dohar 369 — all flagged is_dhaka_subcity), Gazipur 41,
 * Chattogram 8, Rangamati 4 (Barkal 37, not serviced; Rangamati Sadar 33).
 *
 * Owner decision 2026-11-15: a district in `delivery.metro_district_ids` is inside Dhaka metro
 * for EVERY address in it, whatever its upazila's own flag says.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    $this->resolver = fn (): DeliveryChargeResolver => app(DeliveryChargeResolver::class);
    // Monday 2026-11-16, 10:00 in Dhaka (04:00 UTC).
    $this->monday = CarbonImmutable::parse('2026-11-16 04:00:00', 'UTC');
});

/** @return array{District, ?Upazila} */
function place(int $districtId, ?int $upazilaId = null): array
{
    return [District::query()->findOrFail($districtId), $upazilaId === null ? null : Upazila::query()->findOrFail($upazilaId)];
}

function parcelOf(string $price, int $grams, int $quantity = 1): DeliveryParcel
{
    $variant = ProductVariant::factory()->create(['price' => $price, 'weight_grams' => $grams]);

    return new DeliveryParcel([$variant->id => $quantity]);
}

it('prices every zone from the default courier rate card', function (int $district, ?int $upazila, DeliveryZone $zone, string $charge, array $days): void {
    $quote = ($this->resolver)()->quote(...place($district, $upazila), parcel: parcelOf('500.00', 400), now: $this->monday);

    expect($quote->serviceable)->toBeTrue()
        ->and($quote->zone)->toBe($zone)
        ->and($quote->charge?->toDecimal())->toBe($charge)
        ->and($quote->baseCharge?->toDecimal())->toBe($charge)
        ->and($quote->weightSurcharge?->toDecimal())->toBe('0.00')
        ->and($quote->courier?->code)->toBe('steadfast')
        ->and([$quote->eta?->minDays, $quote->eta?->maxDays])->toBe($days);
})->with([
    'Dhaka city (district, no upazila)' => [47, null, DeliveryZone::InsideDhakaMetro, '70.00', [1, 2]],
    'Savar (metro district covers its upazilas)' => [47, 365, DeliveryZone::InsideDhakaMetro, '70.00', [1, 2]],
    'Dhamrai (metro district)' => [47, 366, DeliveryZone::InsideDhakaMetro, '70.00', [1, 2]],
    'Keraniganj (metro district)' => [47, 367, DeliveryZone::InsideDhakaMetro, '70.00', [1, 2]],
    'Nawabganj (metro district)' => [47, 368, DeliveryZone::InsideDhakaMetro, '70.00', [1, 2]],
    'Dohar (metro district)' => [47, 369, DeliveryZone::InsideDhakaMetro, '70.00', [1, 2]],
    'Gazipur (suburb district)' => [41, null, DeliveryZone::DhakaSuburb, '100.00', [2, 3]],
    'Chattogram (outside)' => [8, null, DeliveryZone::OutsideDhaka, '130.00', [3, 4]],
    'Rangamati Sadar (outside)' => [4, 33, DeliveryZone::OutsideDhaka, '130.00', [3, 4]],
]);

it('reports a not-serviced upazila without a charge', function (): void {
    $quote = ($this->resolver)()->quote(...place(4, 37), parcel: parcelOf('500.00', 400), now: $this->monday);

    expect($quote->serviceable)->toBeFalse()
        ->and($quote->zone)->toBeNull()
        ->and($quote->charge)->toBeNull()
        ->and($quote->eta)->toBeNull()
        ->and($quote->upazilaRequired)->toBeTrue();
});

it('asks for the upazila only where it changes the answer', function (): void {
    $resolver = ($this->resolver)();

    expect($resolver->requiresUpazila(District::query()->findOrFail(47)))->toBeTrue() // metro: same price, but the address needs it (rule 31)
        ->and($resolver->requiresUpazila(District::query()->findOrFail(4)))->toBeTrue() // has not-serviced upazilas
        ->and($resolver->requiresUpazila(District::query()->findOrFail(41)))->toBeFalse() // all suburb
        ->and($resolver->requiresUpazila(District::query()->findOrFail(8)))->toBeFalse(); // all outside
});

it('makes every upazila of a metro district inside Dhaka metro, overriding its own flag', function (): void {
    $resolver = ($this->resolver)();

    foreach ([365, 366, 367, 368, 369] as $upazilaId) {
        [$district, $upazila] = place(47, $upazilaId);
        expect($upazila?->is_dhaka_subcity)->toBeTrue()
            ->and($resolver->zoneFor($district, $upazila))->toBe(DeliveryZone::InsideDhakaMetro);
    }
});

it('falls back to the upazila flags once a district is no longer metro', function (): void {
    Setting::put('delivery.metro_district_ids', [], SettingGroup::Delivery);
    $resolver = ($this->resolver)();

    expect($resolver->zoneFor(...place(47, 365)))->toBe(DeliveryZone::DhakaSuburb) // is_dhaka_subcity again
        ->and($resolver->zoneFor(...place(47)))->toBe(DeliveryZone::OutsideDhaka);
});

it('covers a whole district the owner adds as metro, upazila or not', function (): void {
    Setting::put('delivery.metro_district_ids', [8, 47], SettingGroup::Delivery);

    $quote = ($this->resolver)()->quote(...place(8, 66), parcel: parcelOf('500.00', 400), now: $this->monday); // Sitakunda

    expect($quote->zone)->toBe(DeliveryZone::InsideDhakaMetro)
        ->and($quote->charge?->toDecimal())->toBe('70.00')
        ->and([$quote->eta?->minDays, $quote->eta?->maxDays])->toBe([1, 2])
        ->and($quote->upazilaRequired)->toBeTrue() // the address form is unchanged; only the charge moves
        ->and(($this->resolver)()->zoneFor(...place(8)))->toBe(DeliveryZone::InsideDhakaMetro);
});

it('keeps a not-serviced upazila unserviced inside a metro district', function (): void {
    Setting::put('delivery.not_serviced_upazila_ids', [369], SettingGroup::Delivery); // Dohar
    $resolver = ($this->resolver)();

    expect($resolver->zoneFor(...place(47, 369)))->toBeNull()
        ->and($resolver->zoneFor(...place(47, 365)))->toBe(DeliveryZone::InsideDhakaMetro)
        ->and($resolver->zoneFor(...place(47)))->toBe(DeliveryZone::InsideDhakaMetro)
        ->and($resolver->requiresUpazila(District::query()->findOrFail(47)))->toBeTrue();
});

it('applies free delivery at the threshold, not below it', function (string $price, bool $free, ?string $remaining): void {
    $quote = ($this->resolver)()->quote(...place(8), parcel: parcelOf($price, 400), now: $this->monday);

    expect($quote->freeShipping)->toBe($free)
        ->and($quote->charge?->toDecimal())->toBe($free ? '0.00' : '130.00')
        ->and($quote->freeShippingReason)->toBe($free ? 'threshold' : null)
        ->and($quote->freeShippingThreshold?->toDecimal())->toBe('3000.00')
        ->and($quote->freeShippingRemaining?->toDecimal())->toBe($remaining);
})->with([
    'one poisha below' => ['2999.99', false, '0.01'],
    'exactly the threshold' => ['3000.00', true, null],
    'above' => ['4250.00', true, null],
    'well below' => ['2450.00', false, '550.00'],
]);

it('counts quantity toward the threshold and the weight', function (): void {
    $quote = ($this->resolver)()->quote(...place(8), parcel: parcelOf('1500.00', 600, quantity: 2), now: $this->monday);

    expect($quote->subtotal->toDecimal())->toBe('3000.00')
        ->and($quote->weightGrams)->toBe(1200)
        ->and($quote->freeShipping)->toBeTrue()
        ->and($quote->charge?->toDecimal())->toBe('0.00');
});

it('adds a per-kg surcharge for each started kilogram above the included weight', function (int $grams, string $surcharge, string $charge): void {
    $quote = ($this->resolver)()->quote(...place(47), parcel: parcelOf('500.00', $grams), now: $this->monday);

    expect($quote->weightSurcharge?->toDecimal())->toBe($surcharge)
        ->and($quote->charge?->toDecimal())->toBe($charge);
})->with([
    'unknown weight (0 g)' => [0, '0.00', '70.00'],
    'exactly 1 kg' => [1000, '0.00', '70.00'],
    '1 g over' => [1001, '20.00', '90.00'],
    '2 kg' => [2000, '20.00', '90.00'],
    '2.6 kg (Bruder truck)' => [2600, '40.00', '110.00'],
]);

it('uses each provider\'s own weight step (Pathao includes 2 kg, rule 32a)', function (): void {
    CourierProvider::query()->where('code', 'steadfast')->update(['is_active' => false]);
    CourierProvider::query()->where('code', 'pathao')->update(['is_active' => true, 'is_default' => true]);

    $quote = ($this->resolver)()->quote(...place(47), parcel: parcelOf('500.00', 2600), now: $this->monday);

    expect($quote->courier?->code)->toBe('pathao')
        ->and($quote->weightSurcharge?->toDecimal())->toBe('15.00')
        ->and($quote->charge?->toDecimal())->toBe('85.00');
});

it('reads its numbers from settings and the rate card, not from code', function (): void {
    Setting::put('delivery.free_shipping_threshold', '5000.00', SettingGroup::Delivery);
    Setting::put('delivery.suburb_district_ids', [], SettingGroup::Delivery);
    CourierProvider::query()->where('code', 'steadfast')->update(['base_charge_outside' => '150.00']);

    $quote = ($this->resolver)()->quote(...place(41), parcel: parcelOf('3500.00', 400), now: $this->monday);

    expect($quote->zone)->toBe(DeliveryZone::OutsideDhaka)
        ->and($quote->charge?->toDecimal())->toBe('150.00')
        ->and($quote->freeShippingRemaining?->toDecimal())->toBe('1500.00');

    Setting::put('delivery.free_shipping_threshold', null, SettingGroup::Delivery);
    $quote = ($this->resolver)()->quote(...place(41), parcel: parcelOf('9000.00', 400), now: $this->monday);
    expect($quote->freeShipping)->toBeFalse()->and($quote->freeShippingThreshold)->toBeNull();
});

it('lets a free-shipping promotion zero the charge through the seam', function (): void {
    app()->instance(FreeShippingPromotion::class, new class implements FreeShippingPromotion
    {
        public function qualifies(DeliveryParcel $parcel, DeliveryZone $zone, Money $subtotal): bool
        {
            return $zone === DeliveryZone::OutsideDhaka;
        }
    });

    $quote = ($this->resolver)()->quote(...place(8), parcel: parcelOf('500.00', 2600), now: $this->monday);

    expect($quote->charge?->toDecimal())->toBe('0.00')
        ->and($quote->freeShippingReason)->toBe('promotion')
        ->and($quote->freeShippingRemaining)->toBeNull();
});

it('computes absolute dates from the 5 pm Dhaka cut-off, skipping Fridays and holidays', function (string $utc, int $district, array $expected): void {
    $quote = ($this->resolver)()->quote(...place($district), parcel: parcelOf('500.00', 400), now: CarbonImmutable::parse($utc, 'UTC'));

    expect([$quote->eta?->dispatchDate->toDateString(), $quote->eta?->earliest->toDateString(), $quote->eta?->latest->toDateString(), $quote->eta?->cutoffPassed])
        ->toBe($expected);
})->with([
    // Mon 16:59 Dhaka = 10:59 UTC → leaves Monday; metro 1–2 → Tue, Wed.
    'before cut-off' => ['2026-11-16 10:59:00', 47, ['2026-11-16', '2026-11-17', '2026-11-18', false]],
    // Mon 17:00 Dhaka → leaves Tuesday → Wed, Thu.
    'at cut-off' => ['2026-11-16 11:00:00', 47, ['2026-11-17', '2026-11-18', '2026-11-19', true]],
    // Thu 10:00 Dhaka → leaves Thursday; metro +1 skips Friday → Sat, Sun.
    'over a Friday' => ['2026-11-19 04:00:00', 47, ['2026-11-19', '2026-11-21', '2026-11-22', false]],
    // Thu 18:00 Dhaka → Friday is off → leaves Saturday; outside 3–4 → Tue, Wed.
    'after cut-off on Thursday' => ['2026-11-19 12:00:00', 8, ['2026-11-21', '2026-11-24', '2026-11-25', true]],
    // Sun 20:00 UTC = Mon 02:00 Dhaka: the Dhaka date, not the UTC date, decides.
    'UTC evening is the next Dhaka morning' => ['2026-11-15 20:00:00', 47, ['2026-11-16', '2026-11-17', '2026-11-18', false]],
]);

it('skips published holidays', function (): void {
    Setting::put('delivery.holidays', ['2026-11-17'], SettingGroup::Delivery);

    $quote = ($this->resolver)()->quote(...place(47), parcel: parcelOf('500.00', 400), now: $this->monday);

    expect($quote->eta?->earliest->toDateString())->toBe('2026-11-18')
        ->and($quote->eta?->latest->toDateString())->toBe('2026-11-19');
});

it('states the COD advance outside Dhaka above ৳5,000', function (int $district, string $price, ?string $advance): void {
    $quote = ($this->resolver)()->quote(...place($district), parcel: parcelOf($price, 400), now: $this->monday);

    expect($quote->codAdvance?->toDecimal())->toBe($advance);
})->with([
    'outside, above' => [8, '5650.00', '200.00'],
    'outside, exactly 5,000' => [8, '5000.00', null],
    'Dhaka, above' => [47, '5650.00', null],
]);

it('refuses to quote without an active courier provider', function (): void {
    CourierProvider::query()->update(['is_active' => false]);

    expect(fn () => ($this->resolver)()->quote(...place(47), parcel: parcelOf('500.00', 400)))
        ->toThrow(DeliveryQuoteUnavailable::class);
});
