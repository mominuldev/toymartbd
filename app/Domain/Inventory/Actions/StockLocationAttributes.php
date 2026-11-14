<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Customer\Models\District;
use App\Domain\Inventory\DTOs\StockLocationData;

/**
 * The columns a branch / warehouse save writes, shared by Create- and UpdateStockLocation.
 * The division is the district's own (rule 31), never sent separately.
 *
 * @internal
 */
final class StockLocationAttributes
{
    /** @return array<string, mixed> */
    public static function of(StockLocationData $data, District $district): array
    {
        return [
            'name' => $data->name,
            'name_bn' => $data->nameBn,
            'type' => $data->type,
            'division_id' => $district->division_id,
            'district_id' => $district->id,
            'upazila_id' => $data->upazilaId,
            'area' => $data->area,
            'address_line' => $data->addressLine,
            'landmark' => $data->landmark,
            'notes' => $data->notes,
            'is_active' => $data->isActive,
        ];
    }
}
