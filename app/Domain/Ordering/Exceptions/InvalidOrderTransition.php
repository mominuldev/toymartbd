<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A status change the order state machine does not allow (P4-ORD-03). Rendered as a 409
 * `conflict` problem — typically two staff acting on the same order at once.
 */
final class InvalidOrderTransition extends ConflictHttpException
{
    public static function between(string $field, string $from, string $to): self
    {
        return new self("Order {$field} cannot move from {$from} to {$to}.");
    }
}
