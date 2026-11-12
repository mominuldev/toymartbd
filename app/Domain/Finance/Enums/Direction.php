<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** `financial_transactions.direction`: money towards the business (in) or away from it (out). */
enum Direction: string
{
    case In = 'in';
    case Out = 'out';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $d): string => $d->value, self::cases());
    }
}
