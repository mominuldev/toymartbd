<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Exceptions;

use LogicException;

/** An issued credit note is never edited or deleted — like the invoice it corrects. */
final class CreditNoteImmutable extends LogicException
{
    public static function make(string $number): self
    {
        return new self("Credit note {$number} is immutable once issued.");
    }
}
