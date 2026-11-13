<?php

declare(strict_types=1);

namespace App\Domain\Finance\Settlement;

use App\Domain\Finance\DTOs\SettlementLine;
use App\Domain\Finance\Exceptions\UnreadableStatement;
use App\Support\Money;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\OpenSpoutException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reads a courier remittance export (CSV or XLSX) into lines. The first row is the header.
 *
 * RESEARCH GAP: neither courier publishes its portal export's column names (we have no
 * merchant account, 2026-11-13). So the reader takes OUR template columns plus a few likely
 * aliases, and refuses a file without a consignment and a COD column rather than guessing:
 *
 *   consignment_id (consignment, consignment id, cid, tracking id)  — required
 *   cod_amount     (collected amount, cod, collected, cash collection) — required
 *   delivery_charge (charge, delivery fee, charges)                   — optional
 *   cod_fee        (cod charge, cod fee, cod_charge)                  — optional
 *   return_charge  (return fee)                                       — optional
 *   net_amount     (net, payable, paid amount)                        — optional
 *
 * Amounts accept "1,250.00" and "৳1250"; Bengali digits are refused (rule 36: Western only).
 */
final class StatementReader
{
    public const int MAX_LINES = 5000;

    /** @var array<string, list<string>> */
    public const array COLUMNS = [
        'consignment_id' => ['consignment_id', 'consignment', 'consignment id', 'cid', 'tracking id', 'tracking_id'],
        'cod_amount' => ['cod_amount', 'collected amount', 'cod', 'collected', 'cash collection', 'collected_amount', 'cod amount'],
        'delivery_charge' => ['delivery_charge', 'delivery charge', 'charge', 'delivery fee', 'charges'],
        'cod_fee' => ['cod_fee', 'cod fee', 'cod charge', 'cod_charge'],
        'return_charge' => ['return_charge', 'return charge', 'return fee'],
        'net_amount' => ['net_amount', 'net', 'payable', 'paid amount', 'net amount'],
    ];

    /** @return list<SettlementLine> */
    public function read(string $path, string $format): array
    {
        $reader = $format === 'xlsx' ? new XlsxReader : new CsvReader;
        try {
            $reader->open($path);
            $lines = [];
            $map = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    $cells = self::cells($row);
                    if ($map === null) {
                        $map = self::header($cells);

                        continue;
                    }
                    if (array_filter($cells, static fn (string $c): bool => $c !== '') === []) {
                        continue;
                    }
                    $lines[] = self::line($n, $cells, $map);
                    if (count($lines) > self::MAX_LINES) {
                        throw UnreadableStatement::because('A statement may have at most '.self::MAX_LINES.' lines.');
                    }
                }
                break; // first sheet only
            }
            $reader->close();
        } catch (OpenSpoutException) {
            throw UnreadableStatement::because('The file could not be read as '.strtoupper($format).'.');
        }

        if ($lines === []) {
            throw UnreadableStatement::because('The statement has no lines.');
        }

        return $lines;
    }

    /** @return list<string> */
    private static function cells(Row $row): array
    {
        return array_map(static fn (mixed $v): string => trim(is_scalar($v) ? (string) $v : ''), $row->toArray());
    }

    /**
     * @param  list<string>  $cells
     * @return array<string, int>
     */
    private static function header(array $cells): array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $name = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell));
            foreach (self::COLUMNS as $key => $aliases) {
                if (! isset($map[$key]) && in_array($name, $aliases, true)) {
                    $map[$key] = $i;
                }
            }
        }
        foreach (['consignment_id', 'cod_amount'] as $required) {
            if (! isset($map[$required])) {
                throw UnreadableStatement::because("The header has no '{$required}' column.");
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $map
     */
    private static function line(int $n, array $cells, array $map): SettlementLine
    {
        $consignment = $cells[$map['consignment_id']] ?? '';
        if ($consignment === '' || mb_strlen($consignment) > 64) {
            throw UnreadableStatement::because("Row {$n}: the consignment id is missing or too long.");
        }
        $money = static function (string $key) use ($cells, $map, $n): ?Money {
            if (! isset($map[$key])) {
                return null;
            }

            return self::money($cells[$map[$key]] ?? '', $n, $key);
        };

        return new SettlementLine(
            lineNo: $n,
            consignmentId: $consignment,
            codAmount: $money('cod_amount') ?? Money::zero(),
            charges: $money('delivery_charge') ?? Money::zero(),
            codFee: $money('cod_fee') ?? Money::zero(),
            returnCharge: $money('return_charge') ?? Money::zero(),
            net: isset($map['net_amount']) && ($cells[$map['net_amount']] ?? '') !== '' ? $money('net_amount') : null,
        );
    }

    private static function money(string $raw, int $row, string $column): Money
    {
        $clean = str_replace([',', '৳', 'Tk', 'tk', 'BDT', ' '], '', $raw);
        if ($clean === '') {
            return Money::zero();
        }
        if (! preg_match('/^-?\d{1,12}(\.\d{1,2})?$/', $clean)) {
            throw UnreadableStatement::because("Row {$row}: '{$column}' is not an amount.");
        }

        return Money::of($clean);
    }
}
