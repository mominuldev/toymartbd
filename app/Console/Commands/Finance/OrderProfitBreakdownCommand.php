<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Domain\Finance\Reconciliation\OrderBreakdownRow;
use App\Domain\Finance\Reconciliation\OrderProfitBreakdown;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Console\Command;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * The Phase 5 gate's ten-order hand check (docs/ops/PROFIT-RECONCILIATION.md): exports,
 * per order, every component of net profit computed from the source tables beside the
 * same component read from the ledger, with the difference. Read-only — it writes the
 * export file and nothing else. No phone number, name or address leaves the database.
 *
 * Exit code 0 when every order (and, with --from, the period) agrees to the poisha; 1
 * when anything differs; 2 for bad options.
 */
final class OrderProfitBreakdownCommand extends Command
{
    protected $signature = 'toymart:finance:order-breakdown
        {--from= : Y-m-d (Dhaka) — orders PLACED from this day; also the period for the P&L cross-check}
        {--to= : Y-m-d (Dhaka), inclusive; default today}
        {--orders= : Comma-separated order numbers or ids (overrides the date selection of orders)}
        {--format=csv : csv or xlsx}
        {--output= : File to write (default storage/app/private/reconciliation/…)}';

    protected $description = 'Export each order\'s net profit, recomputed from orders/items/returns/refunds, beside the ledger — read-only';

    public function handle(OrderProfitBreakdown $breakdown): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            $this->error('--format must be csv or xlsx.');

            return self::INVALID;
        }

        $from = self::optionString($this->option('from'));
        $to = self::optionString($this->option('to'));
        $references = array_values(array_filter(array_map('trim', explode(',', (string) self::optionString($this->option('orders')))), static fn (string $r): bool => $r !== ''));

        try {
            $period = $from !== null ? ReportPeriod::of($from, $to ?? DhakaDate::today()) : null;
            $orderIds = $breakdown->selectOrders($from, $to, $references);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $rows = $breakdown->rows($orderIds);
        $summary = $period === null ? null : $breakdown->period($period);

        $path = self::optionString($this->option('output'))
            ?? storage_path('app/private/reconciliation/order-breakdown-'.($period?->fromDate() ?? 'orders').'_'.($period?->toDate() ?? DhakaDate::today()).'.'.$format);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0o750, true) && ! is_dir(dirname($path))) {
            $this->error("Cannot create the directory for {$path}.");

            return self::FAILURE;
        }

        $format === 'xlsx' ? $this->writeXlsx($path, $rows, $summary) : $this->writeCsv($path, $rows);

        return $this->report($path, $rows, $summary, count($references) > count($orderIds));
    }

    /** @return list<string> */
    public static function header(): array
    {
        $header = ['order_number', 'order_id', 'status', 'payment_method', 'placed_on', 'delivered_on', 'revenue_recognised'];
        foreach (OrderProfitBreakdown::COMPONENTS as $component) {
            $header[] = "order_{$component}";
            $header[] = "ledger_{$component}";
        }

        return [...$header, 'order_net_profit', 'ledger_net_profit', 'projection_net_profit', 'difference', 'mismatched_components', 'result'];
    }

    /** @return list<string|Money|null> */
    private static function values(OrderBreakdownRow $row): array
    {
        $values = [$row->number, (string) $row->orderId, $row->status, $row->paymentMethod, $row->placedOn, $row->deliveredOn, $row->recognised ? 'yes' : 'no'];
        foreach (OrderProfitBreakdown::COMPONENTS as $component) {
            $values[] = $row->order[$component];
            $values[] = $row->ledger[$component];
        }

        return [
            ...$values,
            $row->orderNet, $row->ledgerNet, $row->projectionNet, $row->difference(),
            implode(' ', $row->mismatched), $row->agrees() ? 'OK' : 'DIFFERS',
        ];
    }

    /**
     * Column totals, for the TOTAL row.
     *
     * @param  list<OrderBreakdownRow>  $rows
     * @return list<string|Money|null>
     */
    private static function totals(array $rows): array
    {
        $values = ['TOTAL', (string) count($rows), null, null, null, null, null];
        $sum = static fn (callable $pick): Money => array_reduce($rows, static fn (Money $c, OrderBreakdownRow $r): Money => $c->plus($pick($r) ?? Money::zero()), Money::zero());
        foreach (OrderProfitBreakdown::COMPONENTS as $component) {
            $values[] = $sum(static fn (OrderBreakdownRow $r): Money => $r->order[$component]);
            $values[] = $sum(static fn (OrderBreakdownRow $r): Money => $r->ledger[$component]);
        }
        $differing = count(array_filter($rows, static fn (OrderBreakdownRow $r): bool => ! $r->agrees()));

        return [
            ...$values,
            $sum(static fn (OrderBreakdownRow $r): Money => $r->orderNet),
            $sum(static fn (OrderBreakdownRow $r): Money => $r->ledgerNet),
            $sum(static fn (OrderBreakdownRow $r): ?Money => $r->projectionNet),
            $sum(static fn (OrderBreakdownRow $r): Money => $r->difference()),
            null,
            $differing === 0 ? 'OK' : "{$differing} DIFFER",
        ];
    }

    /** @param  list<OrderBreakdownRow>  $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $options = new CsvOptions;
        $options->SHOULD_ADD_BOM = true;
        $writer = new CsvWriter($options);
        $writer->openToFile($path);
        $writer->addRow(self::row(self::header(), false));
        foreach ($rows as $row) {
            $writer->addRow(self::row(self::values($row), false));
        }
        $writer->addRow(self::row(self::totals($rows), false));
        $writer->close();
    }

    /**
     * @param  list<OrderBreakdownRow>  $rows
     * @param  array<string, mixed>|null  $summary
     */
    private function writeXlsx(string $path, array $rows, ?array $summary): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Orders');
        $writer->addRow(self::row(self::header(), true));
        foreach ($rows as $row) {
            $writer->addRow(self::row(self::values($row), true));
        }
        $writer->addRow(self::row(self::totals($rows), true));

        if ($summary !== null) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Period');
            foreach (self::summaryLines($summary) as $line) {
                $writer->addRow(self::row($line, true));
            }
        }
        $writer->close();
    }

    /**
     * @param  list<string|Money|null>  $values
     */
    private static function row(array $values, bool $numeric): Row
    {
        return new Row(array_map(static function (string|Money|null $value) use ($numeric): Cell {
            if ($value instanceof Money) {
                // XLSX: a real number, so the accountant's formulas work. CSV: the exact decimal.
                return $numeric ? new NumericCell((float) $value->toDecimal(), null) : new StringCell($value->toDecimal(), null);
            }
            $text = (string) $value;
            if (preg_match('/^[=+\-@]/', $text) === 1) {
                $text = "'".$text; // never a formula from data
            }

            return new StringCell($text, null);
        }, $values));
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return list<list<string|Money|null>>
     */
    private static function summaryLines(array $summary): array
    {
        /** @var array<string, Money> $byType */
        $byType = $summary['other_by_type'];
        $lines = [
            ['Period (Dhaka dates, ledger entries dated in it)', $summary['from'].' to '.$summary['to']],
            ['P&L report net profit', $summary['pnl_net_profit']],
            ['Ledger recognised net, summed directly', $summary['ledger_net_profit']],
            ['  of which order-scoped entries', $summary['order_scoped']],
            ['  of which not tied to an order', $summary['other']],
        ];
        foreach ($byType as $type => $amount) {
            $lines[] = ['    '.$type, $amount];
        }
        $lines[] = ['Result', $summary['agrees'] === true ? 'OK' : 'DIFFERS'];

        return $lines;
    }

    /**
     * @param  list<OrderBreakdownRow>  $rows
     * @param  array<string, mixed>|null  $summary
     */
    private function report(string $path, array $rows, ?array $summary, bool $missingReferences): int
    {
        $differing = array_values(array_filter($rows, static fn (OrderBreakdownRow $r): bool => ! $r->agrees()));

        $this->line('Wrote '.count($rows).' order(s) to '.$path);
        $this->line('Courier charges are 0 on the order side until Phase 6 courier booking records them.');
        if ($missingReferences) {
            $this->warn('Some --orders references matched no order.');
        }

        if ($summary !== null) {
            $this->table(['Period check', 'Amount'], array_map(
                static fn (array $l): array => array_map(static fn (string|Money|null $v): string => $v instanceof Money ? $v->toDecimal() : (string) $v, $l),
                self::summaryLines($summary),
            ));
        }

        if ($differing === []) {
            $this->info('Every order agrees: source tables = ledger = projection, to the poisha.');
        } else {
            $this->table(['order', 'order net', 'ledger net', 'projection', 'difference', 'components'], array_map(
                static fn (OrderBreakdownRow $r): array => [$r->number, $r->orderNet->toDecimal(), $r->ledgerNet->toDecimal(), $r->projectionNet?->toDecimal() ?? '—', $r->difference()->toDecimal(), implode(' ', $r->mismatched)],
                array_slice($differing, 0, 50),
            ));
            $this->error(count($differing).' order(s) differ. Do not correct the ledger by hand: find the code path that booked it.');
        }

        $periodOk = $summary === null || $summary['agrees'] === true;
        if (! $periodOk) {
            $this->error('The P&L report and the ledger disagree for the period (stale rollup? run toymart:finance:rollup for it).');
        }

        return $differing === [] && $periodOk ? self::SUCCESS : self::FAILURE;
    }

    private static function optionString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
