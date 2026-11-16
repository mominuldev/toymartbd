<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/**
 * `capital_entries.kind` (P10 owner capital): money the owner or a partner put in, or took
 * out. Each kind posts its own ledger type; both are cash, never revenue (rule 10).
 */
enum CapitalEntryKind: string
{
    case Contribution = 'contribution';
    case Drawing = 'drawing';

    public function ledgerType(): LedgerEntryType
    {
        return match ($this) {
            self::Contribution => LedgerEntryType::OwnerCapitalIn,
            self::Drawing => LedgerEntryType::OwnerDrawing,
        };
    }

    /** The direction the original entry moves cash; its reversal moves the other way. */
    public function direction(): Direction
    {
        return match ($this) {
            self::Contribution => Direction::In,
            self::Drawing => Direction::Out,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
