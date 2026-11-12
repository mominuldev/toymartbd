<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use LogicException;

/** A programming error against the financial ledger's invariants — never a user-facing condition. */
final class LedgerViolation extends LogicException
{
    public static function appendOnly(): self
    {
        return new self('financial_transactions is append-only: an entry is never updated or deleted; post a reversing entry (rule 8).');
    }
}
