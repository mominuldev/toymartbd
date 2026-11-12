<?php

declare(strict_types=1);

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Ordering\Actions\PlaceOrder;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;

/*
 * The checkout race (DATABASE-DESIGN §4.1, rule 9): 10 customers check out at the same
 * moment against 5 units — exactly 5 orders, 5 clean refusals, no oversell.
 *
 * Real parallelism needs real connections and committed data, which RefreshDatabase's
 * wrapping transaction cannot give. So this test builds its own throwaway database next
 * to the test one (`<db>_checkout_race`), migrates it, commits the fixtures, forks 10
 * processes that each open their own connection and run PlaceOrder behind a start
 * barrier, then drops the database. MySQL only (make test-mysql / CI's mysql group);
 * the SQLite run covers the same rule sequentially in CheckoutApiTest.
 */

const RACE_CONNECTION = 'checkout_race';

function raceDatabase(): string
{
    return config('database.connections.mysql.database').'_checkout_race';
}

function useRaceConnection(string $name): void
{
    config(['database.connections.'.$name => ['database' => raceDatabase()] + config('database.connections.mysql')]);
    DB::setDefaultConnection($name);
}

beforeEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    $this->original = DB::getDefaultConnection();
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.raceDatabase().'`');
    DB::connection('mysql')->statement('CREATE DATABASE `'.raceDatabase().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    useRaceConnection(RACE_CONNECTION);
    Artisan::call('migrate', ['--database' => RACE_CONNECTION, '--force' => true]);
});

afterEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    DB::purge(RACE_CONNECTION);
    DB::setDefaultConnection($this->original);
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.raceDatabase().'`');
});

it('sells exactly 5 units to 10 parallel checkouts', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');

    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
    $variant = CartTestKit::variant('1000.00', 5);

    $carts = [];
    for ($i = 0; $i < 10; $i++) {
        $carts[] = CheckoutKit::cart([$variant->id => 1]);
    }
    // Build every input up front, in the parent, so the children only race.
    $inputs = array_map(fn (int $i): App\Domain\Ordering\Checkout\CheckoutData => CheckoutKit::data($carts[$i], sprintf('0171%07d', $i)), array_keys($carts));

    $dir = sys_get_temp_dir().'/checkout-race-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $go = $dir.'/go';

    $pids = [];
    foreach ($inputs as $i => $input) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child: its own connection (never touch the parent's PDO — SIGKILL below
            // ends the process without its destructor closing the shared socket).
            useRaceConnection(RACE_CONNECTION.'_'.$i);
            $deadline = microtime(true) + 10;
            while (! file_exists($go) && microtime(true) < $deadline) {
                usleep(1000);
            }
            try {
                app(PlaceOrder::class)->handle($input);
                $result = 'placed';
            } catch (InsufficientStock) {
                $result = 'insufficient';
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
    foreach (array_keys($inputs) as $i) {
        $results[] = is_file($dir.'/'.$i) ? (string) file_get_contents($dir.'/'.$i) : 'no result';
        @unlink($dir.'/'.$i);
    }
    @unlink($go);
    @rmdir($dir);

    expect(array_count_values($results))->toBe(['placed' => 5, 'insufficient' => 5]);

    $level = StockLevel::query()->where('variant_id', $variant->id)->sole();
    expect(Order::query()->count())->toBe(5)
        ->and($level->reserved)->toBe(5)
        ->and($level->on_hand)->toBe(5)
        ->and(Order::query()->distinct()->count('number'))->toBe(5);
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel checkouts need MySQL row locking (make test-mysql).');
