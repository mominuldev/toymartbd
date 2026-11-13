<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Requests\ApiFormRequest;
use App\Support\Money;

/**
 * `PUT /settings/delivery` — the store-level free-delivery threshold; needs
 * shipments.manage_rates. null switches free delivery off. Decimal string (rule 6).
 */
final class UpdateDeliverySettingsRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateRates', CourierProvider::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Subtotal at or above which delivery is free, e.g. "3000.00"; null = off. */
            'free_shipping_threshold' => ['present', 'nullable', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
        ];
    }

    public function threshold(): ?Money
    {
        return $this->filled('free_shipping_threshold') ? Money::of($this->string('free_shipping_threshold')->toString()) : null;
    }
}
