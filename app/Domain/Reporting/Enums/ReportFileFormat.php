<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Enums;

enum ReportFileFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $f): string => $f->value, self::cases());
    }
}
