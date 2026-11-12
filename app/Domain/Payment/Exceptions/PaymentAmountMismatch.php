<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The provider (or the courier) reported a paid amount that differs from the payment's.
 * The payment is NOT marked paid (rule 19): staff reconcile it by hand.
 */
final class PaymentAmountMismatch extends ConflictHttpException
{
    public static function for(int $paymentId, string $expected, string $reported): self
    {
        return new self("Payment {$paymentId}: expected {$expected}, provider reported {$reported}.");
    }
}
