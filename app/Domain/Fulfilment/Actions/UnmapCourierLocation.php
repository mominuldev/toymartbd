<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Models\CourierLocation;
use Illuminate\Support\Facades\DB;

/** Clear a mapping. Bookings into that district/upazila block again until it is re-mapped. Idempotent. */
final readonly class UnmapCourierLocation
{
    public function handle(CourierLocation $location, int $by): CourierLocation
    {
        return DB::transaction(static function () use ($location, $by): CourierLocation {
            $row = CourierLocation::query()->lockForUpdate()->findOrFail($location->id);
            $row->forceFill([
                'district_id' => null,
                'upazila_id' => null,
                'is_mapped' => false,
                'mapped_by' => $by,
                'mapped_at' => now(),
            ])->save();

            return $row->refresh();
        });
    }
}
