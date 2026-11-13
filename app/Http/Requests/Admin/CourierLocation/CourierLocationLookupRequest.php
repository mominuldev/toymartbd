<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\CourierLocation;

use App\Domain\Fulfilment\Models\CourierLocation;
use App\Http\Requests\ApiFormRequest;

/**
 * Read-side parameters of the location-mapping screen: a place (district + optional
 * upazila + optional area text) for suggestions and the resolve preview, or `q` for the
 * place picker. Needs shipments.view.
 */
final class CourierLocationLookupRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CourierLocation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Our district (required by suggestions and resolve). */
            'district_id' => ['sometimes', 'required', 'integer', 'exists:districts,id'],
            /** Our upazila in that district; omit for a district-level place (Dhaka city). */
            'upazila_id' => ['sometimes', 'nullable', 'integer', 'exists:upazilas,id'],
            /** The address's free-text area, used only to choose between already-mapped zones. */
            'area' => ['sometimes', 'nullable', 'string', 'max:160'],
            /** Place picker search: district or upazila name, English or Bangla, 2+ characters. */
            'q' => ['sometimes', 'required', 'string', 'min:2', 'max:80'],
        ];
    }

    public function districtId(): ?int
    {
        return $this->filled('district_id') ? $this->integer('district_id') : null;
    }

    public function upazilaId(): ?int
    {
        return $this->filled('upazila_id') ? $this->integer('upazila_id') : null;
    }
}
