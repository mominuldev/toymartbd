<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;

/**
 * The store's delivery policy, read from `settings` (group `delivery`) — every number
 * the resolver uses that is not a courier's rate card. DEFAULTS is the seeded value
 * (DeliverySettingsSeeder writes it) and the fallback for a key nobody has saved yet,
 * so a fresh install quotes the published design values (03-storefront-spec A12.4).
 *
 * PROVISIONAL (owner decision pending, UI-UX-DESIGN-SPEC §10): the zone membership,
 * the not-serviced set, ETAs and the free-delivery threshold are design placeholders.
 * They are data, editable without a deploy.
 */
final class DeliverySettings
{
    public const string PREFIX = 'delivery.';

    /** @var array<string, mixed> */
    public const array DEFAULTS = [
        // Store-level free delivery: a subtotal at or above this is free in every zone. null = off.
        'free_shipping_threshold' => '3000.00',
        // Orders placed after this local time (Asia/Dhaka) leave the next working day.
        'cutoff_time' => '17:00',
        // ISO-8601 weekday numbers with no dispatch or delivery (5 = Friday).
        'non_working_weekdays' => [5],
        // Published holidays, Y-m-d (Dhaka dates). Empty until the owner publishes the calendar.
        'holidays' => [],
        // Working days from dispatch to delivery, [min, max], per zone.
        'eta_days' => [
            'inside_dhaka_metro' => [1, 2],
            'dhaka_suburb' => [2, 3],
            'outside_dhaka' => [3, 4],
        ],
        // A district-level address (no upazila) in one of these districts is the city
        // itself: Dhaka (47). Dhaka city is not an upazila in the geography dataset.
        'metro_district_ids' => [47],
        // Whole districts priced as Dhaka suburb: Gazipur 41, Narayanganj 43,
        // Narsingdi 40, Munshiganj 48, Manikganj 46. Suburb upazilas inside Dhaka district
        // are flagged on the upazila itself (upazilas.is_dhaka_subcity).
        'suburb_district_ids' => [41, 43, 40, 48, 46],
        // Upazilas we do not deliver to (A12.5 seed set): Barkal 37, Belaichari 40,
        // Juraichari 41, Laxmichhari 91, Thanchi 103, Rangabali 241.
        'not_serviced_upazila_ids' => [37, 40, 41, 91, 103, 241],
        // COD orders above `threshold` in these zones need `amount` paid in advance. Shown
        // on the PDP, enforced at checkout (P4-CHK). null = no advance rule.
        'cod_advance' => ['zones' => ['outside_dhaka'], 'threshold' => '5000.00', 'amount' => '200.00'],
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function freeShippingThreshold(): ?Money
    {
        $value = $this->get('free_shipping_threshold');

        return is_string($value) && $value !== '' ? Money::of($value) : null;
    }

    /** @return array{int, int} hour, minute */
    public function cutoff(): array
    {
        $value = (string) $this->get('cutoff_time');
        [$hour, $minute] = array_map(intval(...), explode(':', $value) + [1 => '0']);

        return [$hour, $minute];
    }

    public function cutoffTime(): string
    {
        [$hour, $minute] = $this->cutoff();

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /** @return list<int> */
    public function nonWorkingWeekdays(): array
    {
        return $this->intList('non_working_weekdays');
    }

    /** @return list<string> */
    public function holidays(): array
    {
        $value = $this->get('holidays');

        return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
    }

    /** @return array{int, int} */
    public function etaDays(DeliveryZone $zone): array
    {
        $all = $this->get('eta_days');
        $range = is_array($all) && isset($all[$zone->value]) && is_array($all[$zone->value])
            ? $all[$zone->value]
            : self::DEFAULTS['eta_days'][$zone->value];

        return [(int) $range[0], (int) $range[1]];
    }

    /** @return list<int> */
    public function metroDistrictIds(): array
    {
        return $this->intList('metro_district_ids');
    }

    /** @return list<int> */
    public function suburbDistrictIds(): array
    {
        return $this->intList('suburb_district_ids');
    }

    /** @return list<int> */
    public function notServicedUpazilaIds(): array
    {
        return $this->intList('not_serviced_upazila_ids');
    }

    /** @return array{zones: list<string>, threshold: Money, amount: Money}|null */
    public function codAdvance(): ?array
    {
        $value = $this->get('cod_advance');
        if (! is_array($value) || ! isset($value['threshold'], $value['amount'])) {
            return null;
        }

        return [
            'zones' => array_values(array_map(strval(...), (array) ($value['zones'] ?? []))),
            'threshold' => Money::of((string) $value['threshold']),
            'amount' => Money::of((string) $value['amount']),
        ];
    }

    /** Forget values read in this instance (after an admin edit, in tests). */
    public function refresh(): void
    {
        $this->values = null;
    }

    private function get(string $key): mixed
    {
        if ($this->values === null) {
            $this->values = [];
            foreach (Setting::query()->where('group', SettingGroup::Delivery->value)->get() as $setting) {
                $this->values[substr($setting->key, strlen(self::PREFIX))] = $setting->value;
            }
        }

        return array_key_exists($key, $this->values) ? $this->values[$key] : (self::DEFAULTS[$key] ?? null);
    }

    /** @return list<int> */
    private function intList(string $key): array
    {
        $value = $this->get($key);

        return is_array($value) ? array_values(array_map(intval(...), $value)) : [];
    }
}
