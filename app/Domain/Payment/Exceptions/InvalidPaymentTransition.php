<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use App\Domain\Payment\Enums\PaymentStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A payment status change the transition table does not allow. Rendered as a 409 `conflict`. */
final class InvalidPaymentTransition extends ConflictHttpException
{
    public static function between(PaymentStatus $from, PaymentStatus $to): self
    {
        return new self("A payment cannot move from {$from->value} to {$to->value}.");
    }
}
