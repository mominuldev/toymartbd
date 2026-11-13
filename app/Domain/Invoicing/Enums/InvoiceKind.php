<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Enums;

/**
 * `invoices.kind` (P8 batch 3). A customer order has exactly one `original` invoice (issued on
 * its first print) and, when it is edited upwards after that, one `supplementary` invoice per
 * such edit for the increase — numbered from the same `INV` series and pointing at the
 * original. Supplier (purchase) invoices are always `original`.
 */
enum InvoiceKind: string
{
    case Original = 'original';
    case Supplementary = 'supplementary';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
