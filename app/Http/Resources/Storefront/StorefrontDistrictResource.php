<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Customer\Models\District;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A district for the storefront's address and delivery selectors. `upazila_required`
 * means the delivery charge depends on the upazila (or some upazila is not serviced),
 * so the estimator shows a second selector; `city_option` means an empty upazila is
 * the district's city itself (Dhaka city is not an upazila).
 *
 * Both come from DeliveryChargeResolver, the same rules that price the quote. Load
 * `division` and `upazilas` first to avoid a query per district.
 *
 * @property District $resource
 */
#[SchemaName('StorefrontDistrict')]
final class StorefrontDistrictResource extends ApiResource
{
    /** @return array{id: int, name: string, name_bn: string, division: array{id: int, name: string, name_bn: string}, upazila_required: bool, city_option: bool} */
    public function toArray(Request $request): array
    {
        $district = $this->resource;
        $resolver = app(DeliveryChargeResolver::class);

        return [
            'id' => $district->id,
            'name' => $district->name,
            'name_bn' => $district->name_bn,
            'division' => ['id' => $district->division->id, 'name' => $district->division->name, 'name_bn' => $district->division->name_bn],
            'upazila_required' => $required = $resolver->requiresUpazila($district),
            'city_option' => $required && $resolver->zoneFor($district, null) === DeliveryZone::InsideDhakaMetro,
        ];
    }
}
