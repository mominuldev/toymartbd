<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/** Why a customer sent goods back. Counted by cause, so a fixed list; `other` needs a note. */
enum ReturnReason: string
{
    case DamagedInTransit = 'damaged_in_transit';
    case Defective = 'defective';
    case WrongItem = 'wrong_item';
    case MissingParts = 'missing_parts';
    case NotAsDescribed = 'not_as_described';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
