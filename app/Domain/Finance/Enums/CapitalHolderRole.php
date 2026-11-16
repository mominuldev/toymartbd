<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** `capital_holders.role` (P10): what the person is to the business. A label only — no shares, no profit split. */
enum CapitalHolderRole: string
{
    case Owner = 'owner';
    case Partner = 'partner';
}
