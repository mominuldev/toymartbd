<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Customer\Models\District;
use App\Domain\Inventory\DTOs\StockLocationData;
use App\Domain\Inventory\Models\StockLocation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Adds a branch, warehouse or pickup point (Store settings › Branches & warehouses). A new
 * location is never the default — sales and the storefront keep reading the seeded one —
 * and holds no stock until a transfer, receipt or adjustment books some through the ledger.
 */
final readonly class CreateStockLocation
{
    public function handle(StockLocationData $data): StockLocation
    {
        if ($data->code === null) {
            throw new InvalidArgumentException('A new stock location needs a code.');
        }

        return DB::transaction(function () use ($data): StockLocation {
            $location = new StockLocation;
            $location->code = $data->code;
            $location->is_default = false;
            $location->fill(StockLocationAttributes::of($data, District::query()->findOrFail($data->districtId)));
            $location->save();

            return $location;
        });
    }
}
