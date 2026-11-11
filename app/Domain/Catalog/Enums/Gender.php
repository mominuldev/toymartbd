<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** `products.gender` — nullable: most toys need no gender at all. */
enum Gender: string
{
    case Boys = 'boys';
    case Girls = 'girls';
    case Unisex = 'unisex';
}
