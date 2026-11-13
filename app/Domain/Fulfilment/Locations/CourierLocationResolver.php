<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Locations;

use App\Domain\Fulfilment\DTOs\CourierLocationRef;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use Illuminate\Support\Collection;

/**
 * (provider, district, upazila, area text?) → the provider's city/zone/area ids, or
 * "unmapped" / "ambiguous". Used by booking (agent 1's ShipmentRequestFactory) and by the
 * admin's resolve preview.
 *
 * Only rows a HUMAN mapped are considered (rule 32). There is no name matching against the
 * provider's tree and no fallback from an upazila to its district: an address in an upazila
 * with no mapping is unmapped, full stop. The area text only chooses among already-mapped
 * candidates, and only on an exact normalised name match; anything else is "ambiguous".
 */
final class CourierLocationResolver
{
    public function resolve(CourierProvider $provider, ?int $districtId, ?int $upazilaId, ?string $areaText = null): LocationResolution
    {
        if (! $provider->requires_location_mapping) {
            return LocationResolution::notRequired();
        }
        if ($districtId === null) {
            return LocationResolution::unmapped();
        }

        /** @var Collection<int, CourierLocation> $candidates */
        $candidates = CourierLocation::query()
            ->mapped()
            ->where('provider_id', $provider->id)
            ->where('district_id', $districtId)
            ->when($upazilaId === null, static fn ($q) => $q->whereNull('upazila_id'), static fn ($q) => $q->where('upazila_id', $upazilaId))
            ->orderBy('external_zone_name')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return LocationResolution::unmapped();
        }

        $byText = $this->matchText($candidates, $areaText);
        if ($byText !== null) {
            return LocationResolution::resolved($byText, $byText->ref());
        }

        $zones = $candidates->groupBy('external_zone_id');
        if ($zones->count() === 1) {
            /** @var Collection<int, CourierLocation> $rows */
            $rows = $zones->first();
            $zoneRow = $rows->first(static fn (CourierLocation $l): bool => $l->isZone());
            if ($zoneRow !== null) {
                return LocationResolution::resolved($zoneRow, $zoneRow->ref());
            }
            /** @var CourierLocation $first */
            $first = $rows->first();
            if ($rows->count() === 1) {
                return LocationResolution::resolved($first, $first->ref());
            }

            // Several areas of one zone are mapped here: city + zone is enough for Pathao.
            return LocationResolution::resolved($first, new CourierLocationRef($first->external_city_id, $first->external_zone_id, null));
        }

        return LocationResolution::ambiguous($candidates->values()->all());
    }

    /** @param  Collection<int, CourierLocation>  $candidates */
    private function matchText(Collection $candidates, ?string $areaText): ?CourierLocation
    {
        $text = $areaText === null ? '' : LocationName::exact($areaText);
        if ($text === '') {
            return null;
        }

        $areaHits = $candidates->filter(static fn (CourierLocation $l): bool => ! $l->isZone() && LocationName::exact((string) $l->external_area_name) === $text);
        if ($areaHits->count() === 1) {
            return $areaHits->first();
        }

        $zoneHits = $candidates->filter(static fn (CourierLocation $l): bool => $l->isZone() && LocationName::exact($l->external_zone_name) === $text);

        return $zoneHits->count() === 1 ? $zoneHits->first() : null;
    }
}
