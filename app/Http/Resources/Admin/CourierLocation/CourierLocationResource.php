<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\CourierLocation;

use App\Domain\Fulfilment\Models\CourierLocation;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * One courier zone or area and its mapping to our geography. `district` / `upazila` are
 * set only once a staff member mapped the row — never inferred (rule 32).
 *
 * @property CourierLocation $resource
 */
#[SchemaName('CourierLocation')]
final class CourierLocationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;

        return [
            'id' => $l->id,
            /** @var 'zone'|'area' */
            'level' => $l->isZone() ? 'zone' : 'area',
            'city_id' => $l->external_city_id,
            'city_name' => $l->external_city_name,
            'zone_id' => $l->external_zone_id,
            'zone_name' => $l->external_zone_name,
            'area_id' => $l->external_area_id,
            'area_name' => $l->external_area_name,
            'home_delivery_available' => $l->home_delivery_available,
            'pickup_available' => $l->pickup_available,
            'is_mapped' => $l->is_mapped,
            /** False once the courier stopped returning this row; a mapping on it no longer books. */
            'is_active' => $l->is_active,
            /** @var array{id: int, name: string, name_bn: string}|null */
            'district' => $l->district === null ? null : ['id' => $l->district->id, 'name' => $l->district->name, 'name_bn' => $l->district->name_bn],
            /** @var array{id: int, name: string, name_bn: string}|null */
            'upazila' => $l->upazila === null ? null : ['id' => $l->upazila->id, 'name' => $l->upazila->name, 'name_bn' => $l->upazila->name_bn],
            'mapped_at' => $l->mapped_at?->toIso8601String(),
            'synced_at' => $l->synced_at?->toIso8601String(),
        ];
    }
}
