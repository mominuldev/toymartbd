<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `product_variants.status`. A variant is archived, never deleted: stock movements
 * and order lines reference it.
 */
enum VariantStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
