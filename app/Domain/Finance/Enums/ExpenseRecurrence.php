<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** `expenses.recurrence` of a recurring template: generated on the 1st of each due month (Dhaka). */
enum ExpenseRecurrence: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /** Whether a template first dated in `$startMonth` (1–12) is due in `$month`. */
    public function isDueIn(int $startMonth, int $month): bool
    {
        return match ($this) {
            self::Monthly => true,
            self::Quarterly => (($month - $startMonth) % 3 + 3) % 3 === 0,
            self::Yearly => $month === $startMonth,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
