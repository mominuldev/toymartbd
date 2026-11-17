<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Enums;

/** How soon a suggested action matters. */
enum ActionPriority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
