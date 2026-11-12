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
}
