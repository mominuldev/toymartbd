<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\CourierLocation;

use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

/**
 * `PUT /courier-locations/{provider}/{location}/mapping` — a human maps one courier zone
 * or area to our district (+ upazila). The only way a row becomes mapped (rule 32). The
 * upazila must belong to the district, and the row must still be served by the provider.
 * Needs shipments.manage_locations.
 */
final class MapCourierLocationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', CourierLocation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'district_id' => ['required', 'integer', 'exists:districts,id'],
            /** Omit (or null) for a district-level mapping — an address with no upazila, such as Dhaka city. */
            'upazila_id' => ['nullable', 'integer', 'exists:upazilas,id'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $upazila = $this->upazilaId();
            if ($upazila !== null && (int) Upazila::query()->whereKey($upazila)->value('district_id') !== $this->districtId()) {
                $validator->errors()->add('upazila_id', 'This upazila is not in the chosen district.');
            }
            $location = $this->route('location');
            if ($location instanceof CourierLocation && ! $location->is_active) {
                $validator->errors()->add('location', 'The courier no longer serves this zone. Sync locations and map a current one.');
            }
        }];
    }

    public function districtId(): int
    {
        return $this->integer('district_id');
    }

    public function upazilaId(): ?int
    {
        return $this->filled('upazila_id') ? $this->integer('upazila_id') : null;
    }
}
