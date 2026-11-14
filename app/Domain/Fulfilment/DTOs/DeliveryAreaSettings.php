<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Support\Money;

/**
 * Store settings › Delivery areas & timing — the `delivery.*` settings that decide the zone,
 * the ETA and the COD advance of every quote ({@see DeliverySettings}). Admin edits it as one
 * document; {@see toSettings()} is exactly what is stored, so the resolver reads back what the
 * editor wrote.
 *
 *  - cutoffTime            `delivery.cutoff_time`              "HH:MM", Asia/Dhaka
 *  - etaDays               `delivery.eta_days`                 zone → [min, max] working days
 *  - metroDistrictIds      `delivery.metro_district_ids`       district-level address = inside Dhaka metro
 *  - suburbDistrictIds     `delivery.suburb_district_ids`      whole district = Dhaka suburb
 *  - notServicedUpazilaIds `delivery.not_serviced_upazila_ids` no delivery at all
 *  - codAdvance            `delivery.cod_advance`              null = no advance rule
 */
final readonly class DeliveryAreaSettings
{
    /**
     * @param  array<string, array{int, int}>  $etaDays  keyed by DeliveryZone value, every zone present
     * @param  list<int>  $metroDistrictIds  sorted, distinct
     * @param  list<int>  $suburbDistrictIds  sorted, distinct
     * @param  list<int>  $notServicedUpazilaIds  sorted, distinct
     * @param  array{zones: list<string>, threshold: Money, amount: Money}|null  $codAdvance  zones in DeliveryZone order
     */
    public function __construct(
        public string $cutoffTime,
        public array $etaDays,
        public array $metroDistrictIds,
        public array $suburbDistrictIds,
        public array $notServicedUpazilaIds,
        public ?array $codAdvance,
    ) {}

    /**
     * @param  array<string, array{int, int}>  $etaDays
     * @param  list<int>  $metroDistrictIds
     * @param  list<int>  $suburbDistrictIds
     * @param  list<int>  $notServicedUpazilaIds
     * @param  array{zones: list<string>, threshold: Money, amount: Money}|null  $codAdvance
     */
    public static function make(string $cutoffTime, array $etaDays, array $metroDistrictIds, array $suburbDistrictIds, array $notServicedUpazilaIds, ?array $codAdvance): self
    {
        $eta = [];
        foreach (DeliveryZone::cases() as $zone) {
            $range = $etaDays[$zone->value] ?? DeliverySettings::DEFAULTS['eta_days'][$zone->value];
            $eta[$zone->value] = [(int) $range[0], (int) $range[1]];
        }

        if ($codAdvance !== null) {
            $codAdvance['zones'] = array_values(array_filter(
                array_map(static fn (DeliveryZone $z): string => $z->value, DeliveryZone::cases()),
                static fn (string $z): bool => in_array($z, $codAdvance['zones'], true),
            ));
        }

        return new self($cutoffTime, $eta, self::ids($metroDistrictIds), self::ids($suburbDistrictIds), self::ids($notServicedUpazilaIds), $codAdvance);
    }

    /** What the resolver reads right now (saved value, or the seeded default). */
    public static function fromSettings(DeliverySettings $settings): self
    {
        $eta = [];
        foreach (DeliveryZone::cases() as $zone) {
            $eta[$zone->value] = $settings->etaDays($zone);
        }

        return self::make(
            $settings->cutoffTime(),
            $eta,
            $settings->metroDistrictIds(),
            $settings->suburbDistrictIds(),
            $settings->notServicedUpazilaIds(),
            $settings->codAdvance(),
        );
    }

    /**
     * The stored `delivery.*` values, keyed without the prefix — the exact shape
     * {@see DeliverySettings} reads (money as decimal strings, rule 6).
     *
     * @return array<string, mixed>
     */
    public function toSettings(): array
    {
        return [
            'cutoff_time' => $this->cutoffTime,
            'eta_days' => $this->etaDays,
            'metro_district_ids' => $this->metroDistrictIds,
            'suburb_district_ids' => $this->suburbDistrictIds,
            'not_serviced_upazila_ids' => $this->notServicedUpazilaIds,
            'cod_advance' => $this->codAdvance === null ? null : [
                'zones' => $this->codAdvance['zones'],
                'threshold' => $this->codAdvance['threshold']->toDecimal(),
                'amount' => $this->codAdvance['amount']->toDecimal(),
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids);

        return $ids;
    }
}
