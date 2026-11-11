<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `products.type`. A simple product has exactly one (default) variant; a variable
 * product has one variant per combination of its selected attribute values.
 * Either way the variant is the unit of inventory (DATABASE-DESIGN §1.1).
 */
enum ProductType: string
{
    case Simple = 'simple';
    case Variable = 'variable';
}
