<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Validator;

/**
 * `PATCH /settings/couriers/{provider}` — switch a courier on/off or make it the default;
 * needs shipments.manage_rates. The default provider's rate card prices the storefront, so
 * the default cannot be switched off (make another default first) and neither can the last
 * active provider.
 */
final class UpdateCourierProviderRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        $provider = $this->route('provider');

        return $provider instanceof CourierProvider && ($this->user()?->can('updateRates', $provider) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
            /** Only `true` is meaningful: make this the default (it also switches it on). */
            'is_default' => ['sometimes', 'accepted'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('is_active') || $this->boolean('is_active') || $this->makeDefault()) {
                return;
            }
            /** @var CourierProvider $provider */
            $provider = $this->route('provider');
            if ($provider->is_default) {
                $validator->errors()->add('is_active', 'This courier prices the storefront delivery charge. Make another courier the default first.');
            } elseif ($provider->is_active && ! CourierProvider::query()->where('is_active', true)->whereKeyNot($provider->id)->exists()) {
                $validator->errors()->add('is_active', 'At least one courier must stay active.');
            }
        }];
    }

    public function active(): ?bool
    {
        return $this->has('is_active') ? $this->boolean('is_active') : null;
    }

    public function makeDefault(): bool
    {
        return $this->boolean('is_default');
    }
}
