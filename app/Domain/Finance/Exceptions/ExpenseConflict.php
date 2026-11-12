<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** An expense move its current status does not allow — rendered as a 409 `conflict`. */
final class ExpenseConflict extends ConflictHttpException
{
    public static function notEditable(string $status): self
    {
        return new self("An expense that is {$status} can no longer be changed. Void it and enter a new one.");
    }

    public static function cannot(string $action, string $status): self
    {
        return new self("An expense that is {$status} cannot be {$action}.");
    }

    public static function alreadyPaid(): self
    {
        return new self('This expense is already marked paid.');
    }

    public static function categoryInactive(): self
    {
        return new self('This category is inactive. Choose an active category.');
    }
}
