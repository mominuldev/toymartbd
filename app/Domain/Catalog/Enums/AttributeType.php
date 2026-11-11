<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * `product_attributes.type`. `color` values carry a `color_hex` swatch; `select`
 * and `text` do not.
 */
enum AttributeType: string
{
    case Select = 'select';
    case Color = 'color';
    case Text = 'text';
}
