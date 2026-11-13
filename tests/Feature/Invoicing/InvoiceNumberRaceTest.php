<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueSalesInvoice;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Gap-free invoice numbers under concurrency (P8, N-13): processes issue at the same moment
 * — two of them for the SAME order, one each for two others. Exactly one invoice per order,
 * numbers 1..3 with no gap and no duplicate.
 *
 * Real parallelism needs committed data and separate connections, which RefreshDatabase's
 * wrapping transaction cannot give: like CheckoutConcurrencyTest this builds a throwaway
 * database (`<db>_invoice_race`), forks behind a start barrier, then drops it. MySQL only
 * (CI's mysql group); the SQLite run covers sequencing and rollback in InvoiceIssueTest.
 */

const INVOICE_RACE = 'invoice_race';

function invoiceRaceDatabase(): string
{
    return config('database.connections.mysql.database').'_invoice_race';
}

beforeEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    $this->original = DB::getDefaultConnection();
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.invoiceRaceDatabase().'`');
    DB::connection('mysql')->statement('CREATE DATABASE `'.invoiceRaceDatabase().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    config(['database.connections.'.INVOICE_RACE => ['database' => invoiceRaceDatabase()] + config('database.connections.mysql')]);
    DB::setDefaultConnection(INVOICE_RACE);
    Artisan::call('migrate', ['--database' => INVOICE_RACE, '--force' => true]);
});

afterEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    DB::purge(INVOICE_RACE);
    DB::setDefaultConnection($this->original);
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.invoiceRaceDatabase().'`');
});

it('allocates unique, gap-free numbers to parallel issues, one invoice per order', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');

    $orders = [];
    for ($i = 0; $i < 3; $i++) {
        $orders[] = $order = Order::factory()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);
    }
    $targets = [$orders[0]->id, $orders[0]->id, $orders[1]->id, $orders[2]->id];

    $dir = sys_get_temp_dir().'/invoice-race-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $go = $dir.'/go';
    // Close the parent's socket before forking (see CheckoutConcurrencyTest).
    DB::disconnect(INVOICE_RACE);

    $pids = [];
    foreach ($targets as $i => $orderId) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $deadline = microtime(true) + 10;
            while (! file_exists($go) && microtime(true) < $deadline) {
                usleep(1000);
            }
            try {
                $result = app(IssueSalesInvoice::class)->handle(Order::query()->findOrFail($orderId))->number;
            } catch (Throwable $e) {
                $result = 'error: '.$e::class.' '.$e->getMessage();
            }
            file_put_contents($dir.'/'.$i, $result);
            posix_kill(posix_getpid(), SIGKILL);
        }
        $pids[] = $pid;
    }

    touch($go);
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $results = [];
    foreach (array_keys($targets) as $i) {
        $results[] = is_file($dir.'/'.$i) ? (string) file_get_contents($dir.'/'.$i) : 'no result';
        @unlink($dir.'/'.$i);
    }
    @unlink($go);
    @rmdir($dir);

    expect(array_filter($results, static fn (string $r): bool => ! str_starts_with($r, 'INV-')))->toBe([])
        // Both issuers of order 0 got the same invoice.
        ->and($results[0])->toBe($results[1])
        ->and(Invoice::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and(Invoice::query()->where('order_id', $orders[0]->id)->count())->toBe(1)
        ->and((int) DB::table('invoice_number_sequences')->where('series', 'INV')->value('last_value'))->toBe(3);
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel issues need MySQL row locking (make test-mysql).');
