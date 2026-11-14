<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\StoreSettings;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\DTOs\DeliveryAreaSettings;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Requests\ApiFormRequest;
use App\Models\Enums\Permission;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `PUT /settings/delivery-areas` — Store settings › Delivery areas & timing. The whole
 * document every time. Needs settings.update AND shipments.manage_rates: these values change
 * what every delivery quote charges and promises, like the free-delivery threshold.
 * Money is a decimal string (rule 6); the cut-off is a Dhaka wall-clock time (rule 11).
 */
final class UpdateDeliveryAreasRequest extends ApiFormRequest
{
    private const string MONEY = 'regex:/^\d{1,7}(\.\d{1,2})?$/';

    private const string NOT_ZERO = 'not_regex:/^0+(\.0+)?$/';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can(Permission::SettingsUpdate->value)
            && $user->can('updateRates', CourierProvider::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $day = ['required', 'integer', 'min:0', 'max:30'];

        return [
            /** Orders placed at or after this Dhaka time dispatch the next working day. 24-hour "HH:MM", e.g. "17:00". */
            'cutoff_time' => ['required', 'string', 'date_format:H:i'],
            /** Working days from dispatch to delivery, per zone. */
            'eta_days' => ['required', 'array:inside_dhaka_metro,dhaka_suburb,outside_dhaka'],
            'eta_days.inside_dhaka_metro' => ['required', 'array:min,max'],
            /** 0–30. */
            'eta_days.inside_dhaka_metro.min' => $day,
            /** min–30. */
            'eta_days.inside_dhaka_metro.max' => [...$day, 'gte:eta_days.inside_dhaka_metro.min'],
            'eta_days.dhaka_suburb' => ['required', 'array:min,max'],
            'eta_days.dhaka_suburb.min' => $day,
            'eta_days.dhaka_suburb.max' => [...$day, 'gte:eta_days.dhaka_suburb.min'],
            'eta_days.outside_dhaka' => ['required', 'array:min,max'],
            'eta_days.outside_dhaka.min' => $day,
            'eta_days.outside_dhaka.max' => [...$day, 'gte:eta_days.outside_dhaka.min'],
            /** Districts where an address with no upazila is the city itself — inside Dhaka metro. Their upazilas keep their own zone. */
            'metro_district_ids' => ['present', 'array', 'max:64'],
            'metro_district_ids.*' => ['integer', 'distinct', Rule::exists(District::class, 'id')],
            /** Whole districts priced as Dhaka suburb. Must not also be a metro district. */
            'suburb_district_ids' => ['present', 'array', 'max:64'],
            'suburb_district_ids.*' => ['integer', 'distinct', Rule::exists(District::class, 'id')],
            /** Upazilas we do not deliver to. */
            'not_serviced_upazila_ids' => ['present', 'array', 'max:600'],
            'not_serviced_upazila_ids.*' => ['integer', 'distinct', Rule::exists(Upazila::class, 'id')],
            /** COD orders whose subtotal is above `threshold` in `zones` need `amount` paid in advance. */
            'cod_advance' => ['required', 'array:enabled,zones,threshold,amount'],
            /** false = no advance rule; the other fields are then ignored. */
            'cod_advance.enabled' => ['required', 'boolean'],
            'cod_advance.zones' => ['required_if_accepted:cod_advance.enabled', 'array'],
            'cod_advance.zones.*' => ['string', 'distinct', Rule::enum(DeliveryZone::class)],
            /** Decimal string, e.g. "5000.00". */
            'cod_advance.threshold' => ['required_if_accepted:cod_advance.enabled', 'nullable', 'string', self::MONEY, self::NOT_ZERO],
            /** Decimal string, e.g. "200.00"; less than the threshold. */
            'cod_advance.amount' => ['required_if_accepted:cod_advance.enabled', 'nullable', 'string', self::MONEY, self::NOT_ZERO],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $metro = self::intList($this->input('metro_district_ids'));
            foreach (self::intList($this->input('suburb_district_ids')) as $i => $id) {
                if (in_array($id, $metro, true)) {
                    $validator->errors()->add("suburb_district_ids.{$i}", (string) __('store_settings.district_metro_and_suburb'));
                }
            }

            if (! $this->codAdvanceEnabled() || $validator->errors()->hasAny(['cod_advance.enabled', 'cod_advance.zones', 'cod_advance.threshold', 'cod_advance.amount'])) {
                return;
            }
            $threshold = Money::of($this->string('cod_advance.threshold')->toString());
            $amount = Money::of($this->string('cod_advance.amount')->toString());
            if (! $amount->lessThan($threshold)) {
                $validator->errors()->add('cod_advance.amount', (string) __('store_settings.cod_advance_not_below_threshold'));
            }
        }];
    }

    public function toData(): DeliveryAreaSettings
    {
        $eta = [];
        foreach (DeliveryZone::cases() as $zone) {
            $eta[$zone->value] = [$this->integer("eta_days.{$zone->value}.min"), $this->integer("eta_days.{$zone->value}.max")];
        }

        return DeliveryAreaSettings::make(
            $this->string('cutoff_time')->toString(),
            $eta,
            self::intList($this->input('metro_district_ids')),
            self::intList($this->input('suburb_district_ids')),
            self::intList($this->input('not_serviced_upazila_ids')),
            $this->codAdvanceEnabled() ? [
                'zones' => array_values(array_map(strval(...), (array) $this->input('cod_advance.zones'))),
                'threshold' => Money::of($this->string('cod_advance.threshold')->toString()),
                'amount' => Money::of($this->string('cod_advance.amount')->toString()),
            ] : null,
        );
    }

    private function codAdvanceEnabled(): bool
    {
        return $this->boolean('cod_advance.enabled');
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(intval(...), $value)) : [];
    }
}
