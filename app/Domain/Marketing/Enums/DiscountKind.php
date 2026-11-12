<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Enums;

/** How a promotion's discount is expressed. */
enum DiscountKind: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
