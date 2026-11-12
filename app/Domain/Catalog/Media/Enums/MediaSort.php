<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Enums;

/** The library's sort allowlist (rule 16). Every order breaks ties on id. */
enum MediaSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Name = 'name';
    case Size = 'size';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
