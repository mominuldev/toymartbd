<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use LogicException;

/** A programming error against payment invariants — never a customer-facing condition. */
final class PaymentViolation extends LogicException
{
    public static function neverDeleted(): self
    {
        return new self('A payment is money history and is never deleted.');
    }

    public static function outsideTransaction(): self
    {
        return new self('Payment writes must run inside the calling Action\'s DB::transaction (rule 3).');
    }
}
