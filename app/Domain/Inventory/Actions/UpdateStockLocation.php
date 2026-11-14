<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Customer\Models\District;
use App\Domain\Inventory\DTOs\StockLocationData;
use App\Domain\Inventory\Exceptions\StockLocationConflict;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use Illuminate\Support\Facades\DB;

/**
 * Edits a branch / warehouse, including switching it off. A location is never deleted —
 * its movements are history (rule 8) — only deactivated, after which transfers, receipts
 * and adjustments refuse it and the inventory overview leaves it out.
 *
 * Deactivating is refused (409) for the default location and for one that still holds,
 * reserves or expects stock: those units would vanish from every count. The location row
 * is locked first, so the check and the write see the same state.
 */
final readonly class UpdateStockLocation
{
    public function handle(StockLocation $location, StockLocationData $data): StockLocation
    {
        return DB::transaction(function () use ($location, $data): StockLocation {
            /** @var StockLocation $locked */
            $locked = StockLocation::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active && ! $data->isActive) {
                if ($locked->is_default) {
                    throw StockLocationConflict::defaultLocation();
                }
                $holds = StockLevel::query()
                    ->where('location_id', $locked->id)
                    ->where(fn ($q) => $q->where('on_hand', '!=', 0)->orWhere('reserved', '!=', 0)->orWhere('incoming', '!=', 0))
                    ->exists();
                if ($holds) {
                    throw StockLocationConflict::holdsStock();
                }
            }

            $locked->fill(StockLocationAttributes::of($data, District::query()->findOrFail($data->districtId)));
            $locked->save();

            return $locked;
        });
    }
}
