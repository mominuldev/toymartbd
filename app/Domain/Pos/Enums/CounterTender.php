<?php

declare(strict_types=1);

namespace App\Domain\Pos\Enums;

use App\Domain\Payment\Enums\PaymentMethod;

/**
 * A way the register settles a sale (Figma B · 33 tender buttons). Four of them are money
 * that moves — each maps to the `payments.method` it is recorded as. `due` (baki, owner
 * decision 2026-11-15) is not money: it is the unpaid part of the order, so it has no
 * payment method and no payment row is ever written for it — `payments.method` never holds
 * `due`.
 */
enum CounterTender: string
{
    case Cash = 'cash';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Card = 'card';
    case Due = 'due';

    /** The `payments.method` a tender is recorded as; null for `due` (nothing is paid). */
    public function paymentMethod(): ?PaymentMethod
    {
        return match ($this) {
            self::Cash => PaymentMethod::Cash,
            self::Bkash => PaymentMethod::Bkash,
            self::Nagad => PaymentMethod::Nagad,
            self::Card => PaymentMethod::Card,
            self::Due => null,
        };
    }
}
