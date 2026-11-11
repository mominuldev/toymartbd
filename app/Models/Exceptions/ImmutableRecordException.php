<?php

declare(strict_types=1);

namespace App\Models\Exceptions;

use LogicException;

/** Thrown when code tries to change or remove a row from an insert-only table. */
final class ImmutableRecordException extends LogicException
{
    public static function for(string $table, string $operation): self
    {
        return new self(sprintf('[%s] is insert-only; %s is not allowed.', $table, $operation));
    }
}
