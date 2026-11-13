<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Support\CsvReport;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as CSV or XLSX through openspout — the P4-CAT-10 export pattern
 * (App\Support\CsvReport) extended to Excel and to several sections.
 *
 * - **Formula injection** (OWASP CSV injection): a text cell that starts with = + - @, a tab
 *   or a carriage return is prefixed with an apostrophe, so no spreadsheet evaluates it —
 *   except a plain number such as `-1280.00`, which is data we wrote ourselves. Nothing is
 *   ever written as a formula cell.
 * - CSV is UTF-8 with a BOM, so Excel opens Bangla as UTF-8; several sections are written one
 *   after another, each under its own title row and header. XLSX gets one worksheet per
 *   section, and a plain number is a numeric cell so the owner can sum a column; a money-shaped
 *   value (two decimals) is shown with two decimals. A column the sheet marks as text (a SKU,
 *   a code) stays text even when it looks like a number, so "00123" keeps its zeros.
 * - Streamed straight to the response (php://output), so a large export never sits in memory.
 */
final class Spreadsheet
{
    public const string CSV = 'csv';

    public const string XLSX = 'xlsx';

    /** @return list<string> */
    public static function formats(): array
    {
        return [self::CSV, self::XLSX];
    }

    public static function contentType(string $format): string
    {
        return $format === self::XLSX
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv; charset=UTF-8';
    }

    /**
     * @param  list<Sheet>  $sheets
     */
    public static function download(string $basename, string $format, array $sheets): StreamedResponse
    {
        $filename = $basename.'.'.$format;

        return new StreamedResponse(static function () use ($format, $sheets): void {
            self::write('php://output', $format, $sheets);
        }, 200, [
            'Content-Type' => self::contentType($format),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * One-section report: CSV through App\Support\CsvReport — byte-for-byte the CSV these
     * reports produced before XLSX existed (no title row) — and XLSX as one worksheet.
     */
    public static function downloadSheet(string $basename, string $format, Sheet $sheet): BinaryFileResponse|StreamedResponse
    {
        return $format === self::XLSX
            ? self::download($basename, self::XLSX, [$sheet])
            : CsvReport::download($basename.'.'.self::CSV, $sheet->header, $sheet->rows);
    }

    /**
     * The file's bytes — for a mail attachment.
     *
     * @param  list<Sheet>  $sheets
     */
    public static function contents(string $format, array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'report-');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file for the report.');
        }
        try {
            self::write($path, $format, $sheets);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /** @param  list<Sheet>  $sheets */
    public static function write(string $target, string $format, array $sheets): void
    {
        $xlsx = $format === self::XLSX;
        if ($xlsx) {
            $writer = new XlsxWriter;
        } else {
            $options = new CsvOptions;
            $options->SHOULD_ADD_BOM = true;
            $writer = new CsvWriter($options);
        }
        $writer->openToFile($target);
        // One style object per file: openspout stamps a registered style with that writer's id.
        $money = (new Style)->setFormat('#,##0.00');

        foreach ($sheets as $index => $sheet) {
            if ($xlsx) {
                $current = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $current->setName(self::sheetName($sheet->title, $index));
            } else {
                if ($index > 0) {
                    $writer->addRow(new Row([]));
                }
                if (count($sheets) > 1) {
                    self::add($writer, [$sheet->title], false);
                }
            }
            self::add($writer, $sheet->header, false);
            $text = array_keys(array_intersect($sheet->header, $sheet->text));
            foreach ($sheet->rows as $row) {
                self::add($writer, $row, $xlsx, $text, $money);
            }
        }
        $writer->close();
    }

    /** The guarded text of one cell (exposed for tests). */
    public static function safe(string|int|null $value): string
    {
        $text = (string) $value;
        if (self::isNumber($text)) {
            return $text;
        }

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'".$text : $text;
    }

    /**
     * @param  list<string|int|null>  $values
     * @param  list<int>  $textColumns  column indexes that never become numeric cells
     * @param  Style|null  $money  display format for a money-shaped number: 1,320.00 (the value stays exact)
     */
    private static function add(WriterInterface $writer, array $values, bool $numeric, array $textColumns = [], ?Style $money = null): void
    {
        $cells = [];
        foreach ($values as $index => $value) {
            $text = (string) $value;
            if ($numeric && $text !== '' && ! in_array($index, $textColumns, true) && self::isNumber($text)) {
                $cells[] = str_contains($text, '.')
                    ? new NumericCell((float) $text, preg_match('/\.\d{2}$/', $text) === 1 ? $money : null)
                    : new NumericCell((int) $text, null);

                continue;
            }
            $cells[] = new StringCell(self::safe($value), null);
        }
        $writer->addRow(new Row($cells));
    }

    private static function isNumber(string $text): bool
    {
        return preg_match('/^-?\d{1,15}(\.\d+)?$/', $text) === 1;
    }

    /** Excel: 1–31 characters, none of : \ / ? * [ ]. Section titles are unique by construction. */
    private static function sheetName(string $title, int $index): string
    {
        $clean = trim((string) preg_replace('/[:\\\\\/?*\[\]]/', ' ', $title));

        return mb_substr($clean === '' ? 'Sheet '.($index + 1) : $clean, 0, 31);
    }
}
