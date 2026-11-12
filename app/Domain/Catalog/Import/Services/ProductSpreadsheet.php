<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import\Services;

use App\Domain\Catalog\Import\Enums\SpreadsheetFormat;
use App\Domain\Catalog\Import\Exceptions\UnreadableSpreadsheet;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Options as CsvWriterOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The product spreadsheet format (P4-CAT-10), read and written through openspout.
 *
 * One row per variant, keyed by `sku`. The header row carries the machine column
 * names below, the same in both locales; the import refuses a column it does not
 * know rather than ignoring it. A column absent from the file leaves that field as
 * it is; a column present but blank clears it.
 *
 * Bangla survives a round trip: CSV is written UTF-8 with a BOM (so Excel opens it as
 * UTF-8 instead of the ANSI code page), and the reader skips the BOM. XLSX is UTF-8 by
 * construction.
 *
 * Formula injection: every exported cell is a string cell (never a formula), and in
 * CSV a value starting with = + - @ is prefixed with an apostrophe, which the reader
 * strips again — so a product named "=HYPERLINK(...)" cannot run in Excel.
 */
final class ProductSpreadsheet
{
    /** The file's row limit. More than this is refused as a whole, before any row is written. */
    public const int MAX_ROWS = 5000;

    /** Product-level columns: the same on every row of a variable product. */
    public const array PRODUCT_COLUMNS = [
        'type', 'name', 'name_bn', 'slug', 'status', 'brand', 'primary_category', 'categories',
        'short_description', 'short_description_bn', 'description', 'description_bn',
        'age_min_months', 'age_max_months', 'gender', 'is_educational', 'is_featured',
        'warranty_months', 'safety_note', 'safety_note_bn', 'vat_rate',
    ];

    /** Variant-level columns. `variant_name` is informative (the matrix names variants). */
    public const array VARIANT_COLUMNS = [
        'variant_name', 'barcode', 'price', 'compare_at_price', 'cost_price', 'wholesale_price',
        'weight_grams', 'variant_status', 'opening_stock',
    ];

    /** Columns the export writes but the import never changes. */
    public const array READ_ONLY_COLUMNS = ['type', 'variant_name'];

    /** `categories` holds category slugs separated by this. */
    public const string LIST_SEPARATOR = '|';

    /** @return list<string> */
    public static function columns(bool $withCost): array
    {
        $columns = ['sku', ...self::PRODUCT_COLUMNS, ...self::VARIANT_COLUMNS];

        // Opening stock is an import-only (create) column; the export has nothing to put in it.
        $columns = array_values(array_diff($columns, ['opening_stock']));

        return $withCost ? $columns : array_values(array_diff($columns, ['cost_price']));
    }

    /** @return list<string> */
    public static function importableColumns(): array
    {
        return ['sku', ...self::PRODUCT_COLUMNS, ...self::VARIANT_COLUMNS];
    }

    /**
     * The header (lower-cased, trimmed) and then each data row as column → string|null,
     * keyed by its spreadsheet row number (header = 1). Blank rows are skipped but
     * still counted, so a reported row number is the one Excel shows.
     *
     * @return array{header: list<string>, rows: Generator<int, array<string, string|null>>}
     *
     * @throws UnreadableSpreadsheet
     */
    public function read(string $path, SpreadsheetFormat $format): array
    {
        $reader = $this->reader($format);

        try {
            $reader->open($path);
        } catch (OpenSpoutException $e) {
            throw new UnreadableSpreadsheet('The file could not be read as '.strtoupper($format->value).'.', previous: $e);
        }

        $header = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $header = array_map(static fn (?string $v): string => strtolower(trim((string) $v)), $this->values($row, $format));
                break;
            }
            break;
        }

        if ($header === null || array_filter($header) === []) {
            $reader->close();
            throw new UnreadableSpreadsheet('The file is empty: the first row must name the columns.');
        }

        while ($header !== [] && end($header) === '') {
            array_pop($header);
        }

        $this->assertHeader($header);

        return ['header' => $header, 'rows' => $this->rows($reader, $header, $format)];
    }

    /**
     * @param  list<string>  $header
     * @return Generator<int, array<string, string|null>>
     */
    private function rows(CsvReader|XlsxReader $reader, array $header, SpreadsheetFormat $format): Generator
    {
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;

                    if ($number === 1) {
                        continue;
                    }

                    $values = $this->values($row, $format);

                    if (array_filter($values, static fn (?string $v): bool => $v !== null && trim($v) !== '') === []) {
                        continue;
                    }

                    $assoc = [];
                    foreach ($header as $i => $column) {
                        if ($column !== '') {
                            $assoc[$column] = $values[$i] ?? null;
                        }
                    }

                    yield $number => $assoc;
                }

                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /** @param  list<string>  $header */
    private function assertHeader(array $header): void
    {
        if (! in_array('sku', $header, true)) {
            throw new UnreadableSpreadsheet('The file has no "sku" column: every row is matched to a product by its SKU.');
        }

        $named = array_values(array_filter($header, static fn (string $c): bool => $c !== ''));
        $unknown = array_values(array_diff($named, self::importableColumns()));

        if ($unknown !== []) {
            throw new UnreadableSpreadsheet('Unknown column(s): '.implode(', ', $unknown).'. Use the column names from an export.');
        }

        $duplicates = array_keys(array_filter(array_count_values($named), static fn (int $n): bool => $n > 1));

        if ($duplicates !== []) {
            throw new UnreadableSpreadsheet('Column(s) appear more than once: '.implode(', ', $duplicates).'.');
        }
    }

    /** @return list<string|null> */
    private function values(Row $row, SpreadsheetFormat $format): array
    {
        return array_values(array_map(fn (Cell $cell): ?string => $this->text($cell->getValue(), $format), $row->getCells()));
    }

    private function text(mixed $value, SpreadsheetFormat $format): ?string
    {
        $text = match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            // A numeric XLSX cell: its shortest exact decimal form ("99.5", not "99.500000001").
            is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_string($value) => $value,
            default => null,
        };

        if ($text === null) {
            return null;
        }

        if ($format === SpreadsheetFormat::Csv && preg_match('/^\'[=+\-@]/', $text) === 1) {
            $text = substr($text, 1);
        }

        return $text;
    }

    private function reader(SpreadsheetFormat $format): CsvReader|XlsxReader
    {
        if ($format === SpreadsheetFormat::Csv) {
            $options = new CsvReaderOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

            return new CsvReader($options);
        }

        $options = new XlsxReaderOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        return new XlsxReader($options);
    }

    /**
     * Write rows to `$path`.
     *
     * @param  list<string>  $columns
     * @param  iterable<array<string, string|null>>  $rows
     */
    public function write(string $path, SpreadsheetFormat $format, array $columns, iterable $rows): void
    {
        $writer = $this->writer($format);
        $writer->openToFile($path);

        $writer->addRow($this->row($columns, $format));

        foreach ($rows as $row) {
            $writer->addRow($this->row(array_map(static fn (string $c): string => (string) ($row[$c] ?? ''), $columns), $format));
        }

        $writer->close();
    }

    /** @param  list<string>  $values */
    private function row(array $values, SpreadsheetFormat $format): Row
    {
        return new Row(array_map(static function (string $value) use ($format): Cell {
            if ($format === SpreadsheetFormat::Csv && preg_match('/^[=+\-@]/', $value) === 1) {
                $value = "'".$value;
            }

            return new StringCell($value, null);
        }, $values));
    }

    private function writer(SpreadsheetFormat $format): WriterInterface
    {
        if ($format === SpreadsheetFormat::Csv) {
            $options = new CsvWriterOptions;
            $options->SHOULD_ADD_BOM = true;

            return new CsvWriter($options);
        }

        return new XlsxWriter;
    }
}
