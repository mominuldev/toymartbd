<?php

declare(strict_types=1);

namespace App\Domain\Finance\Reports\Enums;

/** How a report series buckets its Dhaka calendar days (CLAUDE.md rule 11). */
enum ReportGranularity: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $g): string => $g->value, self::cases());
    }
}
