<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An online payment cannot be (re)started for this order (P4-PAY-04): the gateway is not
 * enabled, the order is no longer open, it is already paid, it is waiting for a human, or
 * it has used all its attempts. Rendered as a 409 `conflict`; `reason` is stable.
 */
final class OnlinePaymentNotAllowed extends ConflictHttpException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("An online payment cannot be started for this order ({$reason}).");
    }
}
