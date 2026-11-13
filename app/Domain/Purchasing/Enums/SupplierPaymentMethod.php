<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Enums;

/** `supplier_payments.method` — how the money left the business. */
enum SupplierPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Other = 'other';
}
