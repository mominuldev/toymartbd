<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Enums;

/** The two file formats the product import and export speak. */
enum SpreadsheetFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function mimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv; charset=UTF-8',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
