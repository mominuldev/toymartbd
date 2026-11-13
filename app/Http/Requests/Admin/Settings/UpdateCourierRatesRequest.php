<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Settings;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Requests\ApiFormRequest;

/**
 * `PUT /settings/couriers/{provider}/rates` — the whole rate card; needs
 * shipments.manage_rates. Money is a decimal string (DECIMAL(14,2), never a float — rule 6).
 * `included_weight_grams` is the weight the base charge covers; `per_kg_charge` is added
 * per started kilogram above it (Steadfast steps at 1,000 g, Pathao at 2,000 g — rule 32a).
 */
final class UpdateCourierRatesRequest extends ApiFormRequest
{
    private const string MONEY = 'regex:/^\d{1,5}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $provider = $this->route('provider');

        return $provider instanceof CourierProvider && ($this->user()?->can('updateRates', $provider) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** Customer-facing delivery charge, inside Dhaka metro. Decimal string, e.g. "70.00". */
            'base_charge_dhaka' => ['required', 'string', self::MONEY],
            /** Dhaka suburbs (Gazipur, Narayanganj, Savar …). */
            'base_charge_suburb' => ['required', 'string', self::MONEY],
            /** Outside Dhaka. */
            'base_charge_outside' => ['required', 'string', self::MONEY],
            /** Grams covered by the base charge: 1000 for Steadfast, 2000 for Pathao. Whole 100 g steps. */
            'included_weight_grams' => ['required', 'integer', 'min:0', 'max:30000', 'multiple_of:100'],
            /** Added per started kilogram above `included_weight_grams`. */
            'per_kg_charge' => ['required', 'string', self::MONEY],
            /** The courier's cash-collection fee, percent of the COD amount, e.g. "1.00". */
            'cod_fee_percent' => ['required', 'string', 'regex:/^\d{1,2}(\.\d{1,2})?$/'],
            /** What the courier charges to bring a refused parcel back. */
            'return_charge' => ['required', 'string', self::MONEY],
        ];
    }

    /** @return array<string, mixed> */
    public function rates(): array
    {
        $rates = $this->safe()->only(['base_charge_dhaka', 'base_charge_suburb', 'base_charge_outside', 'included_weight_grams', 'per_kg_charge', 'cod_fee_percent', 'return_charge']);
        $rates['included_weight_grams'] = (int) $rates['included_weight_grams'];
        $rates['cod_fee_percent'] = number_format((float) $rates['cod_fee_percent'], 2, '.', '');

        return $rates;
    }
}
