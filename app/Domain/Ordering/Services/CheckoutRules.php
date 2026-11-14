<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Services\OnlineGateways;
use App\Domain\Risk\Services\RiskSettings;

/**
 * What checkout actually does today, for Store settings › Checkout & delivery rules
 * (Figma B · 38). A read model — it never decides anything checkout reads; every value is
 * taken from the code or setting checkout itself uses, so the screen cannot drift from
 * behaviour (docs/api/checkout-settings.md):
 *
 *  - guest checkout: `POST /storefront/checkout` finds or creates the customer by phone —
 *    no account. The storefront calls it with its server token, never a customer session,
 *    so "accounts only" cannot be enforced; it is a fact, not a setting.
 *  - cash on delivery: CheckoutRequest always accepts `cod` in every serviced zone; online
 *    methods are added only while OnlineGateways enables them. Fixed (rule 33).
 *  - order notes: `customer_note` (≤ 500 chars) is always accepted and stored. Fixed — the
 *    storefront has no switch to hide the field, so turning notes off would reject or drop
 *    what a customer typed.
 *  - risk-based advance: NOT AVAILABLE. No bKash driver is registered and checkout has no
 *    partial-advance payment flow. A high score holds the order for verification instead
 *    (rules 23, 24b); the zone/value COD advance (`delivery.cod_advance`) is arranged by phone.
 *  - delivery charges: the default courier's rate card ({@see CourierProvider::defaultForQuotes()}),
 *    which is what DeliveryChargeResolver quotes; the free-delivery threshold is
 *    `delivery.free_shipping_threshold` (every zone). Both are edited at their own endpoints.
 */
final readonly class CheckoutRules
{
    /** CheckoutRequest's `customer_note` max length. */
    public const int ORDER_NOTE_MAX = 500;

    public function __construct(
        private DeliverySettings $delivery,
        private RiskSettings $risk,
        private OnlineGateways $gateways,
    ) {}

    /**
     * @return array{
     *     guest_checkout: bool,
     *     cash_on_delivery: bool,
     *     online_methods: list<string>,
     *     order_notes: bool,
     *     order_note_max: int,
     *     risk_advance: array{available: bool, blocker: string, bkash_status: string|null, hold_score: int},
     *     cod_advance: array{zones: list<string>, threshold: string, amount: string}|null,
     *     free_shipping_threshold: string|null,
     *     delivery_courier: string|null,
     *     delivery_charges: list<array{zone: string, charge: string|null}>
     * }
     */
    public function snapshot(): array
    {
        $this->delivery->refresh();
        $courier = CourierProvider::defaultForQuotes();
        $codAdvance = $this->delivery->codAdvance();
        $bkash = $this->gateways->status(PaymentMethod::Bkash);

        return [
            'guest_checkout' => true,
            'cash_on_delivery' => true,
            'online_methods' => array_map(static fn (PaymentMethod $m): string => $m->value, $this->gateways->enabled()),
            'order_notes' => true,
            'order_note_max' => self::ORDER_NOTE_MAX,
            'risk_advance' => [
                'available' => false,
                // Even with a bKash driver, checkout cannot take a partial advance yet.
                'blocker' => $bkash['enabled'] ? 'no_partial_advance_flow' : 'no_bkash_gateway',
                'bkash_status' => $bkash['reason'],
                'hold_score' => $this->risk->highThreshold(),
            ],
            'cod_advance' => $codAdvance === null ? null : [
                'zones' => $codAdvance['zones'],
                'threshold' => $codAdvance['threshold']->toDecimal(),
                'amount' => $codAdvance['amount']->toDecimal(),
            ],
            'free_shipping_threshold' => $this->delivery->freeShippingThreshold()?->toDecimal(),
            'delivery_courier' => $courier?->name,
            'delivery_charges' => array_map(static fn (DeliveryZone $zone): array => [
                'zone' => $zone->value,
                'charge' => $courier?->baseChargeFor($zone)->toDecimal(),
            ], DeliveryZone::cases()),
        ];
    }
}
