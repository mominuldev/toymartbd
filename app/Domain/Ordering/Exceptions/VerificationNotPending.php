<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A verify/reject on an order that is not waiting for verification — a 409 `conflict` (a colleague got there first). */
final class VerificationNotPending extends ConflictHttpException
{
    public static function for(string $number, string $status): self
    {
        return new self("Order {$number} is not waiting for verification (verification is {$status}).");
    }
}
