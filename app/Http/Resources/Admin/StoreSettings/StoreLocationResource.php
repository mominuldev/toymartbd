<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\StoreSettings;

use App\Domain\Inventory\Models\StockLocation;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A branch, warehouse or pickup point as Store settings › Branches & warehouses lists it
 * (Figma B · 38). Load `division`, `district`, `upazila` and set `holds_stock` first.
 *
 * @property StockLocation $resource
 */
#[SchemaName('StoreLocation')]
final class StoreLocationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $l = $this->resource;
        $division = $l->division;
        $district = $l->district;
        $upazila = $l->upazila;

        return [
            'id' => $l->id,
            'code' => $l->code,
            'name' => $l->name,
            'name_bn' => $l->name_bn,
            /** @var 'warehouse'|'retail'|'pickup'|null */
            'type' => $l->type?->value,
            /**
             * The inventory card label, e.g. "Retail branch · POS + pickup".
             *
             * @var string|null
             */
            'description' => $l->description,
            /** @var string|null */
            'description_bn' => $l->description_bn,
            /** @var array{id: int, name: string, name_bn: string}|null */
            'division' => $division === null ? null : ['id' => $division->id, 'name' => $division->name, 'name_bn' => $division->name_bn],
            /** @var array{id: int, name: string, name_bn: string}|null */
            'district' => $district === null ? null : ['id' => $district->id, 'name' => $district->name, 'name_bn' => $district->name_bn],
            /** @var array{id: int, name: string, name_bn: string}|null */
            'upazila' => $upazila === null ? null : ['id' => $upazila->id, 'name' => $upazila->name, 'name_bn' => $upazila->name_bn],
            /** @var string|null */
            'area' => $l->area,
            /** @var string|null */
            'address_line' => $l->address_line,
            /** @var string|null */
            'landmark' => $l->landmark,
            /** @var string|null */
            'notes' => $l->notes,
            /** Sales, opening stock and the storefront read this one; it cannot be switched off. */
            'is_default' => $l->is_default,
            'is_active' => $l->is_active,
            /**
             * Holds, reserves or expects stock — it cannot be switched off until that is moved.
             *
             * @var bool
             */
            'holds_stock' => (bool) $l->getAttribute('holds_stock'),
        ];
    }
}
