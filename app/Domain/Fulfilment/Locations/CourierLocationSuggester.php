<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Locations;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;

/**
 * SUGGESTIONS for a human to confirm — never a mapping. Ranks by normalised-name similarity
 * (city ~ district, zone ~ upazila). It is wrong often enough on purpose-built traps
 * (Cumilla/Comilla, landmark-named zones) that nothing here writes a row: the admin shows
 * these labelled "suggestion" and maps only on an explicit confirm (rule 32).
 */
final class CourierLocationSuggester
{
    public const int LIMIT = 5;

    /** Below this name similarity a candidate is noise, not a suggestion. */
    private const float MIN_SCORE = 0.6;

    /**
     * Unmapped, active zones of `$provider` that look like this district/upazila.
     *
     * @return list<array{location: CourierLocation, score: float}>
     */
    public function zonesFor(CourierProvider $provider, District $district, ?Upazila $upazila): array
    {
        $target = $upazila === null ? $district->name : $upazila->name;

        $scored = [];
        CourierLocation::query()
            ->where('provider_id', $provider->id)
            ->where('is_active', true)
            ->where('is_mapped', false)
            ->whereNull('external_area_id')
            ->orderBy('id')
            ->each(static function (CourierLocation $zone) use ($district, $target, &$scored): void {
                $city = LocationName::similarity($district->name, $zone->external_city_name);
                $name = LocationName::similarity($target, $zone->external_zone_name);
                if ($name < self::MIN_SCORE || $city < self::MIN_SCORE) {
                    return;
                }
                $scored[] = ['location' => $zone, 'score' => round(0.4 * $city + 0.6 * $name, 3)];
            }, 500);

        return self::top($scored);
    }

    /**
     * Upazilas (or, for a city-only zone, the district itself) that look like this courier row.
     *
     * @return list<array{district: District, upazila: Upazila|null, score: float}>
     */
    public function upazilasFor(CourierLocation $location): array
    {
        $districts = District::query()->get()
            ->map(static fn (District $d): array => ['district' => $d, 'score' => LocationName::similarity($d->name, $location->external_city_name)])
            ->filter(static fn (array $d): bool => $d['score'] >= self::MIN_SCORE)
            ->keyBy(static fn (array $d): int => $d['district']->id);

        if ($districts->isEmpty()) {
            return [];
        }

        $name = $location->external_area_name ?? $location->external_zone_name;
        $scored = [];
        foreach (Upazila::query()->whereIn('district_id', $districts->keys())->get() as $upazila) {
            $similarity = LocationName::similarity($upazila->name, $name);
            if ($similarity < self::MIN_SCORE) {
                continue;
            }
            $district = $districts[$upazila->district_id];
            $scored[] = ['district' => $district['district'], 'upazila' => $upazila, 'score' => round(0.4 * $district['score'] + 0.6 * $similarity, 3)];
        }

        return self::top($scored);
    }

    /**
     * @template T of array{score: float}
     *
     * @param  list<T>  $scored
     * @return list<T>
     */
    private static function top(array $scored): array
    {
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, self::LIMIT);
    }
}
