<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Resources\ApiResource;
use App\Support\DhakaDate;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The published delivery rate table — what the estimator shows before a district is
 * chosen ("৳70 in Dhaka · ৳130 outside · free over ৳3,000") and the `/delivery-charge`
 * page. Same numbers the resolver uses.
 *
 * @property array{courier: CourierProvider, zones: list<array{zone: DeliveryZone, charge: Money, eta_days: array{int, int}}>, free_shipping_threshold: Money|null, included_weight_grams: int, per_kg_charge: Money, cutoff_time: string} $resource
 */
#[SchemaName('DeliveryRates')]
final class DeliveryRatesResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $rates = $this->resource;

        return [
            /** @var array{code: string, name: string, name_bn: string|null} */
            'courier' => ['code' => $rates['courier']->code, 'name' => $rates['courier']->name, 'name_bn' => $rates['courier']->name_bn],
            /** @var list<array{zone: 'inside_dhaka_metro'|'dhaka_suburb'|'outside_dhaka', charge: string, min_days: int, max_days: int}> */
            'zones' => array_map(static fn (array $z): array => [
                'zone' => $z['zone']->value,
                'charge' => $z['charge']->toDecimal(),
                'min_days' => $z['eta_days'][0],
                'max_days' => $z['eta_days'][1],
            ], $rates['zones']),
            /** @var string|null */
            'free_shipping_threshold' => $rates['free_shipping_threshold']?->toDecimal(),
            /** @var int */
            'included_weight_grams' => $rates['included_weight_grams'],
            /** @var string */
            'per_kg_charge' => $rates['per_kg_charge']->toDecimal(),
            'cutoff_time' => $rates['cutoff_time'],
            'timezone' => DhakaDate::zone(),
        ];
    }
}
