<?php

declare(strict_types=1);

use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Actions\TransferStock;
use App\Domain\Inventory\DTOs\StockTransferData;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Inventory\InventoryOpsKit as Kit;

/*
 * Lock ordering under real concurrency (rule 9, P5-INV): transfers in BOTH directions
 * between two locations, racing checkout-style reservations at the source, over the same
 * two variants listed in opposite orders. Every lock follows (variant_id, location_id),
 * so nothing deadlocks: each process either succeeds or is refused cleanly, the total on
 * hand never changes, and both invariants hold. Same throwaway-database + fork pattern
 * as CheckoutConcurrencyTest; MySQL only (make test-mysql / CI's mysql group).
 */

const TRANSFER_RACE_CONNECTION = 'transfer_race';

function transferRaceDatabase(): string
{
    return config('database.connections.mysql.database').'_transfer_race';
}

beforeEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    $this->original = DB::getDefaultConnection();
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.transferRaceDatabase().'`');
    DB::connection('mysql')->statement('CREATE DATABASE `'.transferRaceDatabase().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    config(['database.connections.'.TRANSFER_RACE_CONNECTION => ['database' => transferRaceDatabase()] + config('database.connections.mysql')]);
    DB::setDefaultConnection(TRANSFER_RACE_CONNECTION);
    Artisan::call('migrate', ['--database' => TRANSFER_RACE_CONNECTION, '--force' => true]);
});

afterEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    DB::purge(TRANSFER_RACE_CONNECTION);
    DB::setDefaultConnection($this->original);
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.transferRaceDatabase().'`');
});

it('runs opposite transfers and reservations in parallel without deadlock or lost units', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');

    $main = StockLocation::defaultId();
    $ctg = Kit::branch()->id;
    $a = Kit::variant('Wooden Alphabet Puzzle', '310.00');
    $b = Kit::variant('Fashion Doll', '1480.00', 'ফ্যাশন পুতুল');
    foreach ([$main, $ctg] as $location) {
        Kit::stock($a->id, 30, $location);
        Kit::stock($b->id, 30, $location);
    }

    // Opposite directions, lines listed in opposite variant orders.
    $jobs = [];
    for ($i = 0; $i < 4; $i++) {
        $jobs[] = ['transfer', $main, $ctg, [$b->id => 2, $a->id => 3]];
        $jobs[] = ['transfer', $ctg, $main, [$a->id => 2, $b->id => 3]];
    }
    $jobs[] = ['reserve', $main, null, [$b->id => 1, $a->id => 1]];
    $jobs[] = ['reserve', $main, null, [$a->id => 2, $b->id => 2]];

    $dir = sys_get_temp_dir().'/transfer-race-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $go = $dir.'/go';
    DB::disconnect(TRANSFER_RACE_CONNECTION);

    $pids = [];
    foreach ($jobs as $i => [$kind, $from, $to, $lines]) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $deadline = microtime(true) + 10;
            while (! file_exists($go) && microtime(true) < $deadline) {
                usleep(1000);
            }
            try {
                if ($kind === 'transfer') {
                    app(TransferStock::class)->handle(new StockTransferData($from, $to, $lines));
                } else {
                    app(ReserveStock::class)->handle($lines);
                }
                $result = 'ok';
            } catch (InsufficientStock|ValidationException) {
                $result = 'refused';
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
    foreach (array_keys($jobs) as $i) {
        $results[] = is_file($dir.'/'.$i) ? (string) file_get_contents($dir.'/'.$i) : 'no result';
        @unlink($dir.'/'.$i);
    }
    @unlink($go);
    @rmdir($dir);

    expect(array_filter($results, static fn (string $r): bool => ! in_array($r, ['ok', 'refused'], true)))->toBe([])
        ->and((int) StockLevel::query()->sum('on_hand'))->toBe(120)
        ->and((int) StockLevel::query()->where('location_id', $main)->sum('reserved'))->toBe(6);
    Kit::assertInvariants();
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel transfers need MySQL row locking (make test-mysql).');
