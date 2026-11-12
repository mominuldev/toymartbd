<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** `expense_categories.type` (DATABASE-DESIGN §3.7). */
enum ExpenseCategoryType: string
{
    case Cogs = 'cogs';
    case Operating = 'operating';
    case Marketing = 'marketing';
    case Logistics = 'logistics';
    case Other = 'other';

    /** The ledger type an expense of this category posts as when the category names none. */
    public function defaultLedgerType(): LedgerEntryType
    {
        return match ($this) {
            self::Cogs => LedgerEntryType::Cogs,
            self::Marketing => LedgerEntryType::Marketing,
            self::Operating, self::Logistics, self::Other => LedgerEntryType::OtherExpense,
        };
    }

    /** Ledger types a category may name explicitly (expense types only, never revenue or cash-only). */
    public const array LEDGER_TYPES = [
        'cogs', 'packaging', 'marketing', 'salary', 'rent', 'utility', 'courier_charge',
        'gateway_fee', 'inventory_write_off', 'other_expense',
    ];

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
