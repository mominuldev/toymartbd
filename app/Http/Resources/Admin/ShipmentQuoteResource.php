<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\DTOs\CourierCostEstimate;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The booking panel's server-side preview (`POST /shipments/quote`). Money is a decimal
 * string. `estimate` comes from the courier's STORED rate card — "not a provider quote".
 * A provider with a `blocked_reason` cannot take the booking (inactive, no credentials, or
 * unverified in production — rule 33a); an order quote with one cannot be booked with that
 * courier (`unmapped_location` blocks until mapped — rule 32; never guessed).
 *
 * @property array{providers: list<array{provider: CourierProvider, trust: string, bookings_count: int, blocked_reason: string|null}>, orders: list<array<string, mixed>>, totals: array<int, array{parcels: int, weight_grams: int, cod_amount: Money, total_if_delivered: Money}>} $resource
 */
#[SchemaName('ShipmentQuote')]
final class ShipmentQuoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var list<array{id: int, code: string, name: string, name_bn: string|null, is_active: bool, is_default: bool, trust: 'verified'|'unverified'|'not_connected', bookings_count: int, requires_location_mapping: bool, supports_bulk: bool, balance: string|null, balance_checked_at: string|null, rate_card_updated_at: string|null, rates: array{base_charge_dhaka: string, base_charge_suburb: string, base_charge_outside: string, included_weight_grams: int, per_kg_charge: string, cod_fee_percent: string, return_charge: string}, blocked_reason: 'inactive'|'not_connected'|'unverified_in_production'|null}> */
            'providers' => array_map(static function (array $row): array {
                /** @var CourierProvider $p */
                $p = $row['provider'];

                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'name_bn' => $p->name_bn,
                    'is_active' => $p->is_active,
                    'is_default' => $p->is_default,
                    'trust' => $row['trust'],
                    'bookings_count' => $row['bookings_count'],
                    'requires_location_mapping' => $p->requires_location_mapping,
                    'supports_bulk' => $p->supports_bulk,
                    'balance' => $p->balance?->toDecimal(),
                    'balance_checked_at' => $p->balance_checked_at?->toIso8601String(),
                    'rate_card_updated_at' => $p->rate_card_updated_at?->toIso8601String(),
                    'rates' => [
                        'base_charge_dhaka' => $p->base_charge_dhaka->toDecimal(),
                        'base_charge_suburb' => $p->base_charge_suburb->toDecimal(),
                        'base_charge_outside' => $p->base_charge_outside->toDecimal(),
                        'included_weight_grams' => $p->included_weight_grams,
                        'per_kg_charge' => $p->per_kg_charge->toDecimal(),
                        'cod_fee_percent' => number_format((float) $p->cod_fee_percent, 2, '.', ''),
                        'return_charge' => $p->return_charge->toDecimal(),
                    ],
                    'blocked_reason' => $row['blocked_reason'],
                ];
            }, $r['providers']),
            /** @var list<array{order_id: int, found: bool, number?: string, status?: string, can_ship?: bool, recipient_name?: string, district?: array{id: int, name: string, name_bn: string}|null, upazila?: array{id: int, name: string, name_bn: string}|null, zone?: 'inside_dhaka_metro'|'dhaka_suburb'|'outside_dhaka'|null, item_count?: int, variant_weight_grams?: int, weight_grams?: int, cod_amount?: string, declared_value?: string, quotes?: list<array{provider_id: int, location_status: 'not_required'|'mapped'|'ambiguous'|'unmapped', location_options: list<array{id: int, city: string, zone: string, area: string|null, mapped_at: string|null}>, blocked_reason: 'missing_address'|'not_serviced'|'unmapped_location'|null, estimate: array{zone: string, weight_grams: int, base_charge: string, weight_surcharge: string, delivery_charge: string, cod_fee_percent: string, cod_fee: string, return_charge: string, total_if_delivered: string, cost_if_refused: string}|null}>}> */
            'orders' => array_map(static function (array $row): array {
                if (! $row['found']) {
                    return ['order_id' => $row['order_id'], 'found' => false];
                }
                $place = static fn (District|Upazila|null $m): ?array => $m === null ? null : ['id' => $m->id, 'name' => $m->name, 'name_bn' => $m->name_bn];

                return [
                    'order_id' => $row['order_id'],
                    'found' => true,
                    'number' => $row['number'],
                    'status' => $row['status'],
                    'can_ship' => $row['can_ship'],
                    'recipient_name' => $row['recipient_name'],
                    'district' => $place($row['district']),
                    'upazila' => $place($row['upazila']),
                    'zone' => $row['zone'],
                    'item_count' => $row['item_count'],
                    'variant_weight_grams' => $row['variant_weight_grams'],
                    'weight_grams' => $row['weight_grams'],
                    'cod_amount' => $row['cod_amount']->toDecimal(),
                    'declared_value' => $row['declared_value']->toDecimal(),
                    'quotes' => array_map(static fn (array $q): array => [
                        'provider_id' => $q['provider_id'],
                        'location_status' => $q['location_status'],
                        'location_options' => $q['location_options'],
                        'blocked_reason' => $q['blocked_reason'],
                        'estimate' => $q['estimate'] instanceof CourierCostEstimate ? self::estimate($q['estimate']) : null,
                    ], $row['quotes']),
                ];
            }, $r['orders']),
            /** @var list<array{provider_id: int, parcels: int, weight_grams: int, cod_amount: string, total_if_delivered: string}> Per courier, over the orders it can price. */
            'totals' => array_map(static fn (int $id, array $t): array => [
                'provider_id' => $id,
                'parcels' => $t['parcels'],
                'weight_grams' => $t['weight_grams'],
                'cod_amount' => $t['cod_amount']->toDecimal(),
                'total_if_delivered' => $t['total_if_delivered']->toDecimal(),
            ], array_keys($r['totals']), $r['totals']),
        ];
    }

    /** @return array<string, string|int> */
    private static function estimate(CourierCostEstimate $e): array
    {
        return [
            'zone' => $e->zone->value,
            'weight_grams' => $e->weightGrams,
            'base_charge' => $e->baseCharge->toDecimal(),
            'weight_surcharge' => $e->weightSurcharge->toDecimal(),
            'delivery_charge' => $e->deliveryCharge->toDecimal(),
            'cod_fee_percent' => $e->codFeePercent,
            'cod_fee' => $e->codFee->toDecimal(),
            'return_charge' => $e->returnCharge->toDecimal(),
            'total_if_delivered' => $e->totalIfDelivered->toDecimal(),
            'cost_if_refused' => $e->costIfRefused->toDecimal(),
        ];
    }
}
