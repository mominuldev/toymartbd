<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * An upazila for the storefront selectors. `serviceable` false = we do not deliver
 * there yet (03-storefront-spec A12.5); it is still listed, so the customer gets the
 * NotServiced state with its options instead of a missing entry.
 *
 * @property Upazila $resource
 */
#[SchemaName('StorefrontUpazila')]
final class StorefrontUpazilaResource extends ApiResource
{
    /** @return array{id: int, name: string, name_bn: string, serviceable: bool} */
    public function toArray(Request $request): array
    {
        $upazila = $this->resource;

        return [
            'id' => $upazila->id,
            'name' => $upazila->name,
            'name_bn' => $upazila->name_bn,
            'serviceable' => app(DeliveryChargeResolver::class)->zoneFor($upazila->district, $upazila) !== null,
        ];
    }
}
