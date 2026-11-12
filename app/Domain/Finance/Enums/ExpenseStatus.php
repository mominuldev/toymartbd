<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * `expenses.status`: pending → approved | rejected; approved → void. Only an approved
 * expense is on the ledger; voiding posts the reversing entry (rule 8).
 */
enum ExpenseStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Void = 'void';

    public function isEditable(): bool
    {
        return $this === self::Pending || $this === self::Rejected;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
