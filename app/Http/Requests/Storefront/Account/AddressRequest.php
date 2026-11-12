<?php

declare(strict_types=1);

namespace App\Http\Requests\Storefront\Account;

use App\Domain\Customer\DTOs\AddressData;
use App\Domain\Customer\Enums\AddressType;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `POST /storefront/account/addresses` and `PATCH …/{address}` (P4-STO-11). The same
 * address rules as checkout (rule 31): district → upazila → area + line + landmark; the
 * upazila is required unless the district offers its city as the empty option. The
 * division follows from the district.
 */
final class AddressRequest extends AccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @var 'home'|'office'|'other' */
            'type' => ['nullable', 'string', Rule::enum(AddressType::class)],
            /** A short name of the customer's own ("Grandparents"). */
            'label' => ['nullable', 'string', 'max:64'],
            'recipient_name' => ['required', 'string', 'min:2', 'max:160'],
            'phone' => ['required', 'string', 'max:32', PhoneRule::make()],
            'district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
            'upazila_id' => ['nullable', 'integer', Rule::exists('upazilas', 'id')],
            'area' => ['required', 'string', 'min:2', 'max:160'],
            'address_line' => ['required', 'string', 'min:5', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['district_id', 'upazila_id'])) {
                return;
            }
            $district = $this->district();
            $upazila = $this->upazila();
            if ($upazila !== null && $upazila->district_id !== $district->id) {
                $validator->errors()->add('upazila_id', (string) __('checkout.upazila.mismatch'));
            }
            if ($upazila === null && ! self::cityOption($district)) {
                $validator->errors()->add('upazila_id', (string) __('checkout.upazila.required'));
            }
        }];
    }

    public function district(): District
    {
        return District::query()->findOrFail($this->integer('district_id'));
    }

    public function upazila(): ?Upazila
    {
        return $this->filled('upazila_id') ? Upazila::query()->findOrFail($this->integer('upazila_id')) : null;
    }

    public function toData(): AddressData
    {
        $nullable = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->trim()->toString() : null;

        return new AddressData(
            type: $this->filled('type') ? AddressType::from($this->string('type')->toString()) : AddressType::Home,
            label: $nullable('label'),
            recipientName: $this->string('recipient_name')->trim()->toString(),
            phone: PhoneNumber::parse($this->string('phone')->toString()),
            district: $this->district(),
            upazila: $this->upazila(),
            area: $this->string('area')->trim()->toString(),
            addressLine: $this->string('address_line')->trim()->toString(),
            landmark: $nullable('landmark'),
            makeDefault: $this->boolean('is_default'),
        );
    }

    /** Same rule as GET /districts' `city_option` and checkout. */
    private static function cityOption(District $district): bool
    {
        $resolver = app(DeliveryChargeResolver::class);

        return $resolver->requiresUpazila($district) && $resolver->zoneFor($district, null) === DeliveryZone::InsideDhakaMetro;
    }
}
