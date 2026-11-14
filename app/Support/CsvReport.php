<?php

declare(strict_types=1);

namespace App\Support;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A report as a CSV download, through openspout (the P4-CAT-10 export pattern).
 *
 * UTF-8 with a BOM, so Excel opens Bangla names as UTF-8 instead of the ANSI code page.
 * Formula injection: every cell is a string cell, and a text value starting with
 * = + - @, a tab or a carriage return is prefixed with an apostrophe — except a plain number such as `-1280.00`,
 * which is data we wrote ourselves and must stay a number in the spreadsheet.
 */
final class CsvReport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<string|int|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'report-').'.csv';

        $options = new Options;
        $options->SHOULD_ADD_BOM = true;
        $writer = new Writer($options);
        $writer->openToFile($path);
        $writer->addRow(self::row($header));
        foreach ($rows as $row) {
            $writer->addRow(self::row($row));
        }
        $writer->close();

        return response()->download($path, $filename, ['Content-Type' => 'text/csv; charset=UTF-8'])->deleteFileAfterSend();
    }

    /** @param  list<string|int|null>  $values */
    private static function row(array $values): Row
    {
        return new Row(array_map(static function (string|int|null $value): StringCell {
            $text = (string) $value;
            if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1 && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
                $text = "'".$text;
            }

            return new StringCell($text, null);
        }, $values));
    }
}
