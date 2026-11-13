<?php

declare(strict_types=1);

use App\Domain\Finance\Reconciliation\OrderProfitBreakdown;
use App\Domain\Payment\Models\Payment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Feature\Finance\FinanceKit;

/*
 * toymart:finance:order-breakdown — the Phase 5 hand-check export
 * (docs/ops/PROFIT-RECONCILIATION.md). Read-only, no personal data in the file, exits
 * non-zero when the source tables and the ledger disagree.
 */

beforeEach(function (): void {
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->path = storage_path('framework/testing/breakdown-'.Str::random(8));
});

afterEach(function (): void {
    foreach (['.csv', '.xlsx'] as $ext) {
        if (is_file($this->path.$ext)) {
            unlink($this->path.$ext);
        }
    }
});

/** @return array<string, array<string, string>> order number → column → value */
function obCsv(string $path): array
{
    $lines = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES) ?: []);
    $header = array_map(static fn (string $h): string => ltrim($h, "\u{FEFF}"), $lines[0]);
    $out = [];
    foreach (array_slice($lines, 1) as $line) {
        $out[$line[0]] = array_combine($header, $line);
    }

    return $out;
}

it('exports one row per order with every component side by side, and exits 0 when all agree', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());

    expect(Artisan::call('toymart:finance:order-breakdown', ['--orders' => $order->number, '--output' => $this->path.'.csv']))->toBe(0);

    $row = obCsv($this->path.'.csv')[$order->number];
    // 2 × 600 − 100 discount + 70 delivery − 2 × 350 = 470.
    expect($row)->toMatchArray([
        'order_item_revenue' => '1200.00', 'ledger_item_revenue' => '1200.00',
        'order_discount' => '100.00', 'ledger_discount' => '100.00',
        'order_delivery_charge' => '70.00', 'ledger_delivery_charge' => '70.00',
        'order_cogs' => '700.00', 'ledger_cogs' => '700.00',
        'order_courier_charges' => '0.00', 'order_gateway_fees' => '0.00',
        'order_net_profit' => '470.00', 'ledger_net_profit' => '470.00', 'projection_net_profit' => '470.00',
        'difference' => '0.00', 'mismatched_components' => '', 'result' => 'OK', 'revenue_recognised' => 'yes',
    ])->and(array_keys($row))->toBe(App\Console\Commands\Finance\OrderProfitBreakdownCommand::header());
});

it('is read-only: no INSERT, UPDATE or DELETE reaches the database', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    $writes = [];
    DB::listen(function (QueryExecuted $q) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $q->sql) === 1) {
            $writes[] = $q->sql;
        }
    });

    Artisan::call('toymart:finance:order-breakdown', ['--from' => now('Asia/Dhaka')->toDateString(), '--output' => $this->path.'.csv']);
    Artisan::call('toymart:finance:order-breakdown', ['--orders' => (string) $order->id, '--format' => 'xlsx', '--output' => $this->path.'.xlsx']);

    expect($writes)->toBe([]);
});

it('writes no phone number, name or address into the file', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    Artisan::call('toymart:finance:order-breakdown', ['--orders' => $order->number, '--output' => $this->path.'.csv']);

    $raw = (string) file_get_contents($this->path.'.csv');
    expect($raw)->not->toContain($order->shipping_phone)
        ->not->toContain(substr($order->shipping_phone, -8))
        ->not->toContain($order->shipping_name)
        ->not->toContain($order->shipping_address);
});

it('flags an order whose ledger was written outside the Finance listeners, and exits 1', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    // A code path that bypassed the ledger contract: an extra COGS row for the order.
    DB::table('financial_transactions')->insert([
        'type' => 'cogs', 'direction' => 'out', 'amount' => '50.00', 'occurred_on' => now('Asia/Dhaka')->toDateString(),
        'source_type' => 'order_reversal', 'source_id' => $order->id, 'is_cash_movement' => false, 'is_recognised_revenue' => true,
        'description' => 'rogue', 'created_at' => now(),
    ]);

    expect(Artisan::call('toymart:finance:order-breakdown', ['--orders' => $order->number, '--output' => $this->path.'.csv']))->toBe(1)
        ->and(Artisan::output())->toContain('differ');
    $row = obCsv($this->path.'.csv')[$order->number];
    expect($row)->toMatchArray([
        'order_cogs' => '700.00', 'ledger_cogs' => '750.00',
        'order_net_profit' => '470.00', 'ledger_net_profit' => '420.00', 'projection_net_profit' => '470.00',
        'difference' => '50.00', 'mismatched_components' => 'cogs', 'result' => 'DIFFERS',
    ]);
});

it('flags a gateway fee the gateway kept but the ledger never booked', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder('bkash'));
    Payment::query()->create([
        'order_id' => $order->id, 'method' => 'bkash', 'gateway' => 'sslcommerz', 'amount' => '1170.00',
        'status' => 'paid', 'fee_amount' => '29.25', 'net_amount' => '1140.75', 'paid_at' => now(),
    ]);

    $row = collect(app(OrderProfitBreakdown::class)->rows([$order->id]))->sole();
    expect($row->order['gateway_fees']->toDecimal())->toBe('29.25')
        ->and($row->ledger['gateway_fees']->toDecimal())->toBe('0.00')
        ->and($row->mismatched)->toBe(['gateway_fees'])
        ->and($row->difference()->toDecimal())->toBe('-29.25')
        ->and($row->agrees())->toBeFalse();
});

it('flags a projection that drifted from the ledger', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    DB::table('order_financials')->where('order_id', $order->id)->update(['net_profit' => '1.00']);

    $row = collect(app(OrderProfitBreakdown::class)->rows([$order->id]))->sole();
    expect($row->mismatched)->toBe([])->and($row->difference()->isZero())->toBeTrue()
        ->and($row->projectionNet?->toDecimal())->toBe('1.00')
        ->and($row->agrees())->toBeFalse();
});

it('writes an xlsx with an Orders sheet and a Period sheet', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    $day = now('Asia/Dhaka')->toDateString();

    expect(Artisan::call('toymart:finance:order-breakdown', ['--from' => $day, '--to' => $day, '--format' => 'xlsx', '--output' => $this->path.'.xlsx']))->toBe(0);

    $reader = new XlsxReader;
    $reader->open($this->path.'.xlsx');
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    expect(array_keys($sheets))->toBe(['Orders', 'Period'])
        ->and($sheets['Orders'][1][0])->toBe($order->number)
        ->and($sheets['Orders'][2][0])->toBe('TOTAL')
        ->and(end($sheets['Period'])[1])->toBe('OK');
});

it('refuses bad options with exit code 2', function (): void {
    expect(Artisan::call('toymart:finance:order-breakdown', ['--format' => 'pdf', '--orders' => '1']))->toBe(2)
        ->and(Artisan::call('toymart:finance:order-breakdown'))->toBe(2)
        ->and(Artisan::call('toymart:finance:order-breakdown', ['--from' => '2026-13-40']))->toBe(2)
        ->and(Artisan::call('toymart:finance:order-breakdown', ['--from' => '2026-11-20', '--to' => '2026-11-01']))->toBe(2);
});

it('warns when an --orders reference matches nothing, and selects by number or id', function (): void {
    $a = FinanceKit::deliver(FinanceKit::shippedOrder());
    $b = FinanceKit::deliver(FinanceKit::shippedOrder());

    expect(app(OrderProfitBreakdown::class)->selectOrders(null, null, [$a->number, (string) $b->id]))->toBe([$a->id, $b->id]);
    Artisan::call('toymart:finance:order-breakdown', ['--orders' => $a->number.',TMB-99-99999', '--output' => $this->path.'.csv']);
    expect(Artisan::output())->toContain('matched no order');
});

it('is a console tool only: no HTTP route exposes it, and its default output is private storage', function (): void {
    $uris = collect(Route::getRoutes()->getRoutes())->map(static fn ($r): string => $r->uri())->implode(' ');
    expect($uris)->not->toContain('breakdown');

    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    Artisan::call('toymart:finance:order-breakdown', ['--orders' => $order->number]);
    $written = trim(Str::after(Artisan::output(), ' to '));
    $written = strtok($written, "\n") ?: '';
    expect($written)->toStartWith(storage_path('app/private/reconciliation/'))->and(is_file($written))->toBeTrue();
    unlink($written);
});
