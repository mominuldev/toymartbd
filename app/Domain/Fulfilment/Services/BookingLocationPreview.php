<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\CourierProvider;
use Illuminate\Support\Collection;

/**
 * Before booking, show staff whether an address can be sent to a courier that needs its own
 * zone ids (rule 32, Pathao): the explicitly mapped `courier_locations` rows for the order's
 * upazila (or, for a district-level address such as Dhaka city, the district). Read-only —
 * the booking job re-checks the same rule and refuses to guess. Never matches by name.
 *
 * Result: `not_required` (Steadfast), `mapped` (exactly one), `ambiguous` (several — staff
 * must choose one), `unmapped` (none — booking is blocked until someone maps it).
 */
final class BookingLocationPreview
{
    /**
     * @return array{status: 'not_required'|'mapped'|'ambiguous'|'unmapped', options: Collection<int, CourierLocation>}
     */
    public function for(CourierProvider $provider, ?int $districtId, ?int $upazilaId): array
    {
        if (! $provider->requires_location_mapping) {
            return ['status' => 'not_required', 'options' => collect()];
        }
        if ($districtId === null && $upazilaId === null) {
            return ['status' => 'unmapped', 'options' => collect()];
        }

        $query = CourierLocation::query()->mapped()->where('provider_id', $provider->id);
        $upazilaId !== null
            ? $query->where('upazila_id', $upazilaId)
            : $query->where('district_id', $districtId)->whereNull('upazila_id');

        /** @var Collection<int, CourierLocation> $rows */
        $rows = $query->orderBy('external_city_name')->orderBy('external_zone_name')->orderBy('external_area_name')->limit(50)->get();

        return [
            'status' => match ($rows->count()) {
                0 => 'unmapped',
                1 => 'mapped',
                default => 'ambiguous',
            },
            'options' => $rows,
        ];
    }
}
