<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** How an expense was paid. A null `payment_method` means not paid yet (an accrual). */
enum ExpensePaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Card = 'card';
    case Cheque = 'cheque';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
