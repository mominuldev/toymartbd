<?php

declare(strict_types=1);

namespace App\Domain\Payment\Enums;

/** `payments.method` (DATABASE-DESIGN §3.5). COD is first-class (rule 33). */
enum PaymentMethod: string
{
    case Cod = 'cod';
    case Sslcommerz = 'sslcommerz';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';

    /** Paid through a hosted gateway session before dispatch (P4-PAY-02); reserves stock until paid or expired. */
    public function isOnline(): bool
    {
        return in_array($this, [self::Sslcommerz, self::Bkash, self::Nagad, self::Card], true);
    }
}
