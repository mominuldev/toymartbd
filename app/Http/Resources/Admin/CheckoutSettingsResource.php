<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Checkout & delivery rules as checkout applies them (Store settings, Figma B · 38). Money is
 * a DECIMAL(14,2) string (rule 6). Rows marked fixed are facts of the checkout code, not
 * settings; `risk_advance.available` is false until a bKash driver and a partial-advance
 * flow exist.
 *
 * @property array{guest_checkout: bool, cash_on_delivery: bool, online_methods: list<string>, order_notes: bool, order_note_max: int, risk_advance: array{available: bool, blocker: string, bkash_status: string|null, hold_score: int}, cod_advance: array{zones: list<string>, threshold: string, amount: string}|null, free_shipping_threshold: string|null, delivery_courier: string|null, delivery_charges: list<array{zone: string, charge: string|null}>} $resource
 */
#[SchemaName('CheckoutSettings')]
final class CheckoutSettingsResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var bool Always true: checkout finds or creates the customer by phone; no account needed. Not configurable. */
            'guest_checkout' => $r['guest_checkout'],
            /** @var bool Always true: COD is accepted in every serviced zone (rule 33). Not configurable. */
            'cash_on_delivery' => $r['cash_on_delivery'],
            /** @var list<string> Online payment methods checkout accepts right now, besides COD. */
            'online_methods' => $r['online_methods'],
            /** @var bool Always true: the customer note is accepted and stored. Not configurable. */
            'order_notes' => $r['order_notes'],
            /** @var int Longest note checkout accepts. */
            'order_note_max' => $r['order_note_max'],
            /**
             * Risk-based advance payment (bKash). `available` is false until it can be enforced;
             * `blocker` is `no_bkash_gateway` or `no_partial_advance_flow`; `hold_score` is the
             * score at which an order is held for verification instead (never cancelled).
             *
             * @var array{available: bool, blocker: string, bkash_status: string|null, hold_score: int}
             */
            'risk_advance' => $r['risk_advance'],
            /**
             * The COD advance rule checkout applies (held until staff arrange it by phone);
             * null = no rule. Zones are `inside_dhaka_metro` | `dhaka_suburb` | `outside_dhaka`.
             *
             * @var array{zones: list<string>, threshold: string, amount: string}|null
             */
            'cod_advance' => $r['cod_advance'],
            /** @var string|null Subtotal at or above which delivery is free in every zone; null = off. Edited via PUT /settings/delivery. */
            'free_shipping_threshold' => $r['free_shipping_threshold'],
            /** @var string|null The default courier whose rate card prices the customer's delivery charge; null = none active. */
            'delivery_courier' => $r['delivery_courier'],
            /**
             * Base charge per zone the customer is quoted (before weight surcharge); null when no courier is active.
             *
             * @var list<array{zone: string, charge: string|null}>
             */
            'delivery_charges' => $r['delivery_charges'],
        ];
    }
}
