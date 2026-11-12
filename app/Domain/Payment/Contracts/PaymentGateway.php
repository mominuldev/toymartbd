<?php

declare(strict_types=1);

namespace App\Domain\Payment\Contracts;

use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\Payment;
use App\Support\Money;

/**
 * A payment driver (P4-PAY-01, rule 5): adding a gateway means writing one of these and
 * registering it in PaymentGatewayRegistry — never touching order code. COD is a driver
 * like any other (rule 33).
 *
 * Drivers talk to the provider and translate; they never write to the database. Every
 * method may do network I/O, so callers invoke them only OUTSIDE a transaction (rule 4)
 * and then apply the result with PaymentTransitions inside one.
 */
interface PaymentGateway
{
    /** The method this driver serves. */
    public function method(): PaymentMethod;

    /** Open the payment with the provider (create a hosted session, or nothing for COD). */
    public function initiate(Payment $payment, PaymentContext $context): GatewayResult;

    /**
     * Ask the provider for the authoritative status and amount (rule 19). Webhooks call
     * this rather than trusting their own body.
     */
    public function verify(Payment $payment): GatewayResult;

    /** Return money to the customer. */
    public function refund(Payment $payment, Money $amount, string $reason): RefundResult;
}
