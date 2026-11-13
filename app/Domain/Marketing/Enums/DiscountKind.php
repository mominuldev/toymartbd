<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Enums;

/** How a promotion's discount is expressed. `price` (a set sold for a fixed amount) is valid for bundles only. */
enum DiscountKind: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case Price = 'price';
}
