<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\StoreSettings;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A district with its division and upazilas, for the branch address picker (rule 31). Load
 * `division` and `upazilas` first.
 *
 * @property District $resource
 */
#[SchemaName('StoreLocationDistrict')]
final class StoreLocationDistrictResource extends ApiResource
{
    /** @return array{id: int, name: string, name_bn: string, division: array{id: int, name: string, name_bn: string}, upazilas: list<array{id: int, name: string, name_bn: string}>} */
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => $d->id,
            'name' => $d->name,
            'name_bn' => $d->name_bn,
            'division' => ['id' => $d->division->id, 'name' => $d->division->name, 'name_bn' => $d->division->name_bn],
            'upazilas' => $d->upazilas->sortBy('name')->values()->map(static fn (Upazila $u): array => ['id' => $u->id, 'name' => $u->name, 'name_bn' => $u->name_bn])->all(),
        ];
    }
}
