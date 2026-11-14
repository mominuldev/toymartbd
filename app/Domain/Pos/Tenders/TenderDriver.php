<?php

declare(strict_types=1);

namespace App\Domain\Pos\Tenders;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\Payment;
use App\Support\Money;

/**
 * A counter tender (rule 5): cash, bKash, Nagad, card. Adding a way to pay at the counter is
 * a driver class plus one line in {@see TenderRegistry} — never a change to ChargeSale.
 *
 * Like a Payment driver it never writes; it translates what happened at the counter into a
 * {@see GatewayResult} that Payment's PaymentTransitions applies (pending → paid). No gateway
 * is live for the counter (2026-11-15), so every driver here is a MANUAL tender: the cashier
 * saw the money arrive (cash in hand, a bKash/Nagad send-money SMS, a card-terminal slip) and
 * types the reference. When a live terminal or merchant API exists, its driver verifies with
 * the provider instead — outside the order transaction (rule 4).
 */
interface TenderDriver
{
    public function method(): PaymentMethod;

    /** A non-cash tender needs the transaction id / approval code the cashier saw. */
    public function requiresReference(): bool;

    /** Only cash can be over-tendered and give change. */
    public function givesChange(): bool;

    /** The cashier has the money: the payment is paid at `$amount`. */
    public function capture(Payment $payment, Money $amount, ?string $reference, int $cashierId): GatewayResult;
}
