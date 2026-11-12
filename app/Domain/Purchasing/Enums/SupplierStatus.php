<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Enums;

/** `suppliers.status`. Archived, never deleted: purchases and payments reference it. */
enum SupplierStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
