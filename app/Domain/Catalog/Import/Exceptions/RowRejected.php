<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Exceptions;

use RuntimeException;

/**
 * One import row cannot be written. `problems` is column → messages; a null column is
 * a problem with the row as a whole. Recorded in product_import_errors, never skipped
 * silently.
 */
final class RowRejected extends RuntimeException
{
    /** @param  array<string, list<string>>  $problems  column ('' for the whole row) → messages */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Import row rejected.');
    }

    public static function column(?string $column, string $message): self
    {
        return new self([$column ?? '' => [$message]]);
    }
}
