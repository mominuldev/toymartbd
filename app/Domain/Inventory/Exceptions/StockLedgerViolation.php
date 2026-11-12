<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use LogicException;

/** A programming error against the stock ledger's invariants — never a customer-facing condition. */
final class StockLedgerViolation extends LogicException
{
    public static function appendOnly(): self
    {
        return new self('stock_movements is append-only: a movement is never updated or deleted (rule 8).');
    }

    public static function outsideTransaction(): self
    {
        return new self('Stock writes must run inside the calling Action\'s DB::transaction (rules 3 and 9).');
    }

    public static function nonPositiveQuantity(): self
    {
        return new self('A stock quantity must be a positive whole number; the movement type carries the sign.');
    }

    public static function releaseExceedsReserved(int $variantId): self
    {
        return new self("Releasing more than is reserved for variant {$variantId} — a reservation was released twice.");
    }
}
