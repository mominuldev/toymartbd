<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Enums;

/** The glyphs a USP band item may use; the storefront maps each to a Phosphor icon and tile colour. */
enum UspIcon: string
{
    case Cod = 'cod';
    case Return = 'return';
    case Authentic = 'authentic';
    case Support = 'support';
    case Delivery = 'delivery';
    case Gift = 'gift';
    case Educational = 'educational';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $icon): string => $icon->value, self::cases());
    }
}
