<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

use App\Domain\Customer\Models\Upazila;
use App\Domain\Inventory\DTOs\StockLocationData;
use App\Domain\Inventory\Enums\StockLocationType;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The fields a branch / warehouse save shares between `POST /settings/locations` and
 * `PUT /settings/locations/{location}`; needs settings.update. Address per rule 31:
 * district → upazila (optional — a city address has none) → area + line + landmark. The
 * division is the district's own and is not sent.
 */
abstract class StockLocationRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permission::SettingsUpdate->value) ?? false;
    }

    /** @return array<string, mixed> */
    protected function sharedRules(): array
    {
        return [
            /** e.g. `Chattogram · Agrabad`. */
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            /** warehouse = online fulfilment; retail = shop with POS and click & collect; pickup = partner pickup point. */
            'type' => ['required', 'string', Rule::enum(StockLocationType::class)],
            'district_id' => ['required', 'integer', Rule::exists('districts', 'id')],
            /** Must belong to `district_id`; empty for a city address. */
            'upazila_id' => ['nullable', 'integer', Rule::exists('upazilas', 'id')],
            'area' => ['nullable', 'string', 'max:160'],
            'address_line' => ['required', 'string', 'min:3', 'max:500'],
            /** What a courier rider looks for (rule 31). */
            'landmark' => ['nullable', 'string', 'max:255'],
            /** The facts line, e.g. `Pathao pickup 4–7 pm · 2 packers`. */
            'notes' => ['nullable', 'string', 'max:160'],
            /** False switches the location off; refused (409) for the default one or one holding stock. */
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['district_id', 'upazila_id']) || ! $this->filled('upazila_id')) {
                return;
            }
            $belongs = Upazila::query()->whereKey($this->integer('upazila_id'))->where('district_id', $this->integer('district_id'))->exists();
            if (! $belongs) {
                $validator->errors()->add('upazila_id', (string) __('store_settings.upazila_mismatch'));
            }
        }];
    }

    public function toData(): StockLocationData
    {
        return new StockLocationData(
            name: trim($this->string('name')->toString()),
            nameBn: self::optional($this->input('name_bn')),
            type: StockLocationType::from($this->string('type')->toString()),
            districtId: $this->integer('district_id'),
            upazilaId: $this->filled('upazila_id') ? $this->integer('upazila_id') : null,
            area: self::optional($this->input('area')),
            addressLine: trim($this->string('address_line')->toString()),
            landmark: self::optional($this->input('landmark')),
            notes: self::optional($this->input('notes')),
            isActive: $this->boolean('is_active'),
            code: $this->code(),
        );
    }

    protected function code(): ?string
    {
        return null;
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
