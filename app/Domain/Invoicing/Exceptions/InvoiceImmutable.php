<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use LogicException;

/** An issued invoice is never edited or deleted (PRD N-13: reprints show the details current then). */
final class InvoiceImmutable extends LogicException
{
    public static function make(string $number): self
    {
        return new self("Invoice {$number} is immutable once issued.");
    }
}
