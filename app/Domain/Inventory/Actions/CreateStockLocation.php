<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Customer\Models\District;
use App\Domain\Inventory\DTOs\StockLocationData;
use App\Domain\Inventory\Exceptions\BranchModeConflict;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Services\BranchMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adds a branch, warehouse or pickup point (Store settings › Branches & warehouses). A new
 * location is never the default — sales and the storefront keep reading the seeded one —
 * and holds no stock until a transfer, receipt or adjustment books some through the ledger.
 *
 * Refused while the store runs as a single shop ({@see BranchMode}): the active rows are locked in
 * ascending id order first — UpdateBranchMode's order — so the mode read and the insert agree.
 */
final readonly class CreateStockLocation
{
    public function handle(StockLocationData $data): StockLocation
    {
        if ($data->code === null) {
            throw new InvalidArgumentException('A new stock location needs a code.');
        }

        return DB::transaction(function () use ($data): StockLocation {
            StockLocation::query()->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            if ($data->isActive && ! BranchMode::isMulti()) {
                throw BranchModeConflict::singleMode();
            }

            $location = new StockLocation;
            $location->code = $data->code;
            $location->is_default = false;
            $location->fill(StockLocationAttributes::of($data, District::query()->findOrFail($data->districtId)));
            $location->save();

            return $location;
        });
    }
}
