<?php

declare(strict_types=1);

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Division;
use App\Domain\Customer\Models\Upazila;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\DB;

/**
 * P3-DAT-03 — Bangladesh geography seeded from database/data/geo (SOURCE.md).
 * Division names below use the dataset's spellings (Chattagram, Barisal), not guesses.
 */
const BANGLA_ONLY = '/^[\p{Bengali}\x{200C}\x{200D} ]+$/u';

/** @return list<array<string, mixed>> */
function geoDataset(string $file): array
{
    /** @var list<array<string, mixed>> */
    return json_decode((string) file_get_contents(database_path("data/geo/{$file}")), true, 16, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $this->seed(GeographySeeder::class);
});

it('seeds exactly 8 divisions and 64 districts', function (): void {
    expect(Division::query()->count())->toBe(8)
        ->and(District::query()->count())->toBe(64);
});

it('puts the right number of districts in each division', function (string $division, int $districts): void {
    expect(Division::query()->where('name', $division)->firstOrFail()->districts()->count())->toBe($districts);
})->with([
    'Dhaka' => ['Dhaka', 13],
    'Chattogram' => ['Chattagram', 11],
    'Rajshahi' => ['Rajshahi', 8],
    'Khulna' => ['Khulna', 10],
    'Barishal' => ['Barisal', 6],
    'Sylhet' => ['Sylhet', 4],
    'Rangpur' => ['Rangpur', 8],
    'Mymensingh' => ['Mymensingh', 4],
]);

it('seeds every upazila in the dataset — 495', function (): void {
    expect(Upazila::query()->count())
        ->toBe(count(geoDataset('upazilas.json')))
        ->toBe(495);
});

it('leaves no district without upazilas', function (): void {
    expect(District::query()->doesntHave('upazilas')->count())->toBe(0);
});

it('matches the dataset per-district upazila counts', function (): void {
    $expected = array_count_values(array_map(fn (array $u): int => (int) $u['district_id'], geoDataset('upazilas.json')));
    ksort($expected);

    $actual = Upazila::query()->selectRaw('district_id, count(*) as c')->groupBy('district_id')
        ->orderBy('district_id')->pluck('c', 'district_id')->map(fn (mixed $c): int => (int) $c)->all();

    expect($actual)->toBe($expected);
});

it('gives every row a non-empty Bengali-script Bangla name', function (string $table): void {
    $names = DB::table($table)->pluck('name_bn');

    expect($names)->not->toBeEmpty();
    foreach ($names as $name) {
        expect($name)->toBeString()->not->toBe('')->toMatch(BANGLA_ONLY);
    }
})->with(['divisions', 'districts', 'upazilas']);

it('stores a unique official P-code on every row', function (string $table, int $count): void {
    expect(DB::table($table)->whereNotNull('pcode')->distinct()->count('pcode'))->toBe($count)
        ->and(DB::table($table)->where('pcode', 'not like', 'BD%')->count())->toBe(0);
})->with([['divisions', 8], ['districts', 64], ['upazilas', 495]]);

it('round-trips Bangla names byte for byte', function (): void {
    foreach (geoDataset('upazilas.json') as $row) {
        $stored = Upazila::query()->findOrFail($row['id']);
        expect($stored->name_bn)->toBe($row['name_bn']);
    }

    expect(Division::query()->findOrFail(6)->name_bn)->toBe('ঢাকা')
        ->and(District::query()->where('name', 'Comilla')->firstOrFail()->name_bn)->toBe('কুমিল্লা')
        ->and(Upazila::query()->where('name', 'Savar')->firstOrFail()->name_bn)->toBe('সাভার');
});

it('wires the relations division → district → upazila', function (): void {
    $savar = Upazila::query()->where('name', 'Savar')->firstOrFail();

    expect($savar->district->name)->toBe('Dhaka')
        ->and($savar->district->division->name)->toBe('Dhaka')
        ->and(Division::query()->where('name', 'Dhaka')->firstOrFail()->upazilas()->count())
        ->toBe(count(array_filter(
            geoDataset('upazilas.json'),
            fn (array $u): bool => in_array((int) $u['district_id'], District::query()->where('division_id', 6)->pluck('id')->all(), true),
        )));
});

it('applies the provisional Dhaka zone flags by id only', function (): void {
    /** @var array{is_inside_dhaka_metro: list<int>, is_dhaka_subcity: list<int>} $zones */
    $zones = json_decode((string) file_get_contents(database_path('data/geo/dhaka-zones.json')), true, 16, JSON_THROW_ON_ERROR);

    expect(Upazila::query()->where('is_inside_dhaka_metro', true)->pluck('id')->sort()->values()->all())
        ->toBe($zones['is_inside_dhaka_metro'])
        ->and(Upazila::query()->where('is_dhaka_subcity', true)->orderBy('id')->pluck('name')->all())
        ->toBe(['Savar', 'Dhamrai', 'Keraniganj', 'Nawabganj', 'Dohar'])
        ->and(Upazila::query()->where('is_inside_dhaka_metro', true)->where('is_dhaka_subcity', true)->count())->toBe(0);
});

it('is idempotent — a second run changes no count and keeps ids', function (): void {
    $before = [
        Division::query()->count(), District::query()->count(), Upazila::query()->count(),
        Upazila::query()->orderBy('id')->pluck('pcode', 'id')->all(),
    ];

    Upazila::query()->whereKey(365)->update(['name_bn' => 'tampered', 'is_dhaka_subcity' => false]);
    $this->seed(GeographySeeder::class);
    $this->seed(GeographySeeder::class);

    expect([
        Division::query()->count(), District::query()->count(), Upazila::query()->count(),
        Upazila::query()->orderBy('id')->pluck('pcode', 'id')->all(),
    ])->toBe($before)
        ->and(Upazila::query()->findOrFail(365)->name_bn)->toBe('সাভার')
        ->and(Upazila::query()->findOrFail(365)->is_dhaka_subcity)->toBeTrue();
});
