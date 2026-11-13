<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use Illuminate\Validation\ValidationException;

/** A settlement file we cannot read — a 422 on `file`, naming the row when there is one. */
final class UnreadableStatement
{
    public static function because(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
