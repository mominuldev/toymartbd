<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An approve or reject on an adjustment that is no longer pending (already approved,
 * rejected, or never needed approval). Rendered as a 409 `conflict` problem — the second
 * of two approvers clicking at once lands here, never on a second set of movements.
 */
final class AdjustmentAlreadyDecided extends ConflictHttpException
{
    public static function for(string $number, string $status): self
    {
        return new self("Adjustment {$number} is {$status}, not pending.");
    }
}
