<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A manual COD receipt the books cannot accept — rendered as a 409 `conflict`. */
final class CodReceiptRefused extends ConflictHttpException
{
    public static function notCod(): self
    {
        return new self('Only a cash-on-delivery order has a courier receivable.');
    }

    public static function notRecognised(): self
    {
        return new self('The order has not been delivered, so nothing is receivable yet.');
    }

    public static function exceedsOutstanding(string $outstanding): self
    {
        return new self("The amount is more than the outstanding receivable ({$outstanding}).");
    }
}
