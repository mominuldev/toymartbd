<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;

/** Reads an XLSX export back with openspout: sheet name → rows of cell values. */
final class Xlsx
{
    /** @return array<string, list<list<mixed>>> */
    public static function sheets(TestResponse $response): array
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'xlsx-').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();
        @unlink($path);

        return $sheets;
    }

    /**
     * The only sheet, as header-keyed rows.
     *
     * @return array{name: string, header: list<mixed>, rows: list<array<string, mixed>>}
     */
    public static function single(TestResponse $response): array
    {
        $sheets = self::sheets($response);
        expect($sheets)->toHaveCount(1);
        $name = (string) array_key_first($sheets);
        $all = $sheets[$name];
        $header = array_shift($all);

        return [
            'name' => $name,
            'header' => $header,
            'rows' => array_map(static fn (array $row): array => array_combine(array_map(strval(...), $header), array_pad($row, count($header), '')), $all),
        ];
    }

    /** A decimal string from the JSON report as the number the sheet must hold ('' when null). */
    public static function number(?string $decimal): float|int|string
    {
        if ($decimal === null) {
            return '';
        }

        return str_contains($decimal, '.') ? (float) $decimal : (int) $decimal;
    }
}
