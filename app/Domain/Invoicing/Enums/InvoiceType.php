<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

use App\Domain\Invoicing\Contracts\NumberSeries;

/**
 * `invoices.type` (DATABASE-DESIGN §3.5). Each type has its own gap-free number series:
 * `INV-YY-NNNNNN` for a customer (sales) invoice, `PINV-YY-NNNNNN` for a supplier
 * (purchase) invoice.
 */
enum InvoiceType: string implements NumberSeries
{
    case Sales = 'sales';
    case Purchase = 'purchase';

    public function series(): string
    {
        return match ($this) {
            self::Sales => 'INV',
            self::Purchase => 'PINV',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
