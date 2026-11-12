<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront;

use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /delivery-quote` query: where (district, optional upazila of that district) and
 * what (an active variant and a quantity). The route's token ability authorises it.
 */
final class DeliveryQuoteRequest extends ApiFormRequest
{
    public const int MAX_QUANTITY = 99;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
            'upazila_id' => [
                'nullable', 'integer',
                Rule::exists('upazilas', 'id')->where('district_id', (int) $this->query('district_id')),
            ],
            'variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->where('status', VariantStatus::Active->value),
            ],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];
    }

    public function district(): District
    {
        return District::query()->findOrFail((int) $this->validated('district_id'));
    }

    public function upazila(): ?Upazila
    {
        $id = $this->validated('upazila_id');

        return $id === null ? null : Upazila::query()->findOrFail((int) $id);
    }

    public function parcel(): DeliveryParcel
    {
        $variant = $this->validated('variant_id');

        return new DeliveryParcel($variant === null ? [] : [(int) $variant => (int) ($this->validated('quantity') ?? 1)]);
    }
}
