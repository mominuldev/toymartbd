<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront\Account;

use App\Domain\Customer\Models\Address;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Division;
use App\Domain\Customer\Models\Upazila;
use App\Http\Resources\ApiResource;
use App\Support\PhoneNumber;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A saved address in the customer's own book (rule 31: division → district → upazila →
 * area + line + landmark). Load `division`, `district` and `upazila` first.
 *
 * @property Address $resource
 */
#[SchemaName('StorefrontAddress')]
final class StorefrontAddressResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $address = $this->resource;

        return [
            'id' => $address->id,
            /** @var 'home'|'office'|'other' */
            'type' => $address->type->value,
            /** @var string|null */
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            /** @var string */
            'phone' => PhoneNumber::tryParse($address->phone)?->national() ?? '',
            /** @var array{id: int, name: string, name_bn: string} */
            'division' => self::place($address->division),
            /** @var array{id: int, name: string, name_bn: string} */
            'district' => self::place($address->district),
            /** @var array{id: int, name: string, name_bn: string}|null */
            'upazila' => $address->upazila === null ? null : self::place($address->upazila),
            /** @var string|null */
            'area' => $address->area,
            'address_line' => $address->address_line,
            /** @var string|null */
            'landmark' => $address->landmark,
            'is_default' => $address->is_default_shipping,
        ];
    }

    /** @return array{id: int, name: string, name_bn: string} */
    private static function place(Division|District|Upazila $place): array
    {
        return ['id' => $place->id, 'name' => $place->name, 'name_bn' => $place->name_bn];
    }
}
