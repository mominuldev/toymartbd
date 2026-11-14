<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\StoreSettings;

use App\Domain\Fulfilment\DTOs\DeliveryAreaSettings;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Store settings › Delivery areas & timing: the `delivery.*` values every delivery quote reads
 * (DeliverySettings). Provisional business values until the owner confirms them
 * (UI-UX-DESIGN-SPEC §10). District and upazila names come from `GET /settings/locations/districts`.
 *
 * @property DeliveryAreaSettings $resource
 */
#[SchemaName('DeliveryAreas')]
final class DeliveryAreasResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;
        $eta = [];
        foreach ($s->etaDays as $zone => [$min, $max]) {
            $eta[$zone] = ['min' => $min, 'max' => $max];
        }

        return [
            /**
             * Dhaka wall-clock "HH:MM"; orders at or after it dispatch the next working day.
             *
             * @var string
             */
            'cutoff_time' => $s->cutoffTime,
            /**
             * Always "Asia/Dhaka".
             *
             * @var string
             */
            'timezone' => DhakaDate::zone(),
            /**
             * Working days from dispatch to delivery, per zone.
             *
             * @var array{inside_dhaka_metro: array{min: int, max: int}, dhaka_suburb: array{min: int, max: int}, outside_dhaka: array{min: int, max: int}}
             */
            'eta_days' => $eta,
            /**
             * Districts whose district-level address (no upazila) is inside Dhaka metro.
             *
             * @var list<int>
             */
            'metro_district_ids' => $s->metroDistrictIds,
            /**
             * Whole districts priced as Dhaka suburb.
             *
             * @var list<int>
             */
            'suburb_district_ids' => $s->suburbDistrictIds,
            /** @var list<int> */
            'not_serviced_upazila_ids' => $s->notServicedUpazilaIds,
            /**
             * COD orders above `threshold` in `zones` need `amount` paid in advance. Disabled =
             * no rule (zones empty, money null). Money as decimal strings.
             *
             * @var array{enabled: bool, zones: list<'inside_dhaka_metro'|'dhaka_suburb'|'outside_dhaka'>, threshold: string|null, amount: string|null}
             */
            'cod_advance' => $s->codAdvance === null
                ? ['enabled' => false, 'zones' => [], 'threshold' => null, 'amount' => null]
                : [
                    'enabled' => true,
                    'zones' => $s->codAdvance['zones'],
                    'threshold' => $s->codAdvance['threshold']->toDecimal(),
                    'amount' => $s->codAdvance['amount']->toDecimal(),
                ],
        ];
    }
}
