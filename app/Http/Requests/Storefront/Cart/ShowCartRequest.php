<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Cart;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use Illuminate\Validation\Rule;

/**
 * `GET /cart` — optional destination (`district_id`, `upazila_id` of that district) to
 * include the delivery charge from DeliveryChargeResolver. Without it, `delivery` and
 * `totals.shipping_charge` are null and the total excludes delivery.
 */
final class ShowCartRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')],
            'upazila_id' => [
                'nullable', 'integer',
                Rule::exists('upazilas', 'id')->where('district_id', (int) $this->query('district_id')),
            ],
        ];
    }

    public function district(): ?District
    {
        $id = $this->validated('district_id');

        return $id === null ? null : District::query()->findOrFail((int) $id);
    }

    public function upazila(): ?Upazila
    {
        $id = $this->validated('upazila_id');

        return $id === null ? null : Upazila::query()->findOrFail((int) $id);
    }
}
