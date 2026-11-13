<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Models\CourierLocation;
use Illuminate\Support\Facades\DB;

/**
 * A staff member maps one courier zone/area to our district (+ upazila) — the ONLY way a
 * `courier_locations` row becomes mapped (rule 32). Re-mapping overwrites. `upazila_id`
 * NULL is a district-level mapping (a Dhaka-city address has no upazila). That the upazila
 * belongs to the district, and that the row is active, is the FormRequest's check.
 */
final readonly class MapCourierLocation
{
    public function handle(CourierLocation $location, int $districtId, ?int $upazilaId, int $mappedBy): CourierLocation
    {
        return DB::transaction(static function () use ($location, $districtId, $upazilaId, $mappedBy): CourierLocation {
            $row = CourierLocation::query()->lockForUpdate()->findOrFail($location->id);
            $row->forceFill([
                'district_id' => $districtId,
                'upazila_id' => $upazilaId,
                'is_mapped' => true,
                'mapped_by' => $mappedBy,
                'mapped_at' => now(),
            ])->save();

            return $row->refresh();
        });
    }
}
