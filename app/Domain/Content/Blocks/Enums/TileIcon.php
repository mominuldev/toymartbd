<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Enums;

/**
 * The glyphs a feature tile or a call-to-action band button may use (2026-11-16, About us
 * 170:10502). The storefront maps each to one Phosphor icon; the admin shows the same list.
 */
enum TileIcon: string
{
    case ShieldCheck = 'shield_check';
    case CheckCircle = 'check_circle';
    case Gift = 'gift';
    case MapPin = 'map_pin';
    case Truck = 'truck';
    case Money = 'money';
    case Chat = 'chat';
    case Phone = 'phone';
    case Star = 'star';
    case Heart = 'heart';
    case Puzzle = 'puzzle';
    case Books = 'books';
    case Clock = 'clock';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $icon): string => $icon->value, self::cases());
    }
}
