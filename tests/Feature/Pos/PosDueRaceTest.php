<?php

declare(strict_types=1);

use App\Domain\Customer\Enums\CustomerSource;
use App\Domain\Customer\Models\Customer;
use App\Domain\Ordering\Models\Order;
use App\Domain\Pos\Actions\ChargeSale;
use App\Domain\Pos\DTOs\SaleData;
use App\Domain\Pos\DTOs\TenderInput;
use App\Domain\Pos\Enums\CounterTender;
use App\Domain\Pos\Services\PosDueSettings;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\PhoneNumber;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Pos\PosKit;

/*
 * The due-limit race (owner decision 2026-11-15): two cashiers, on two drawers (so the session
 * lock does not serialise them), put ৳600 each on the same customer's due at the same moment
 * against a ৳1,000 limit. Exactly one passes — the limit is re-checked under the customer's
 * row lock with a locking read of their orders. Same harness as CheckoutConcurrencyTest: its
 * own throwaway database, committed fixtures, forked children behind a start barrier. MySQL
 * only (make test-mysql); the SQLite run covers the rule sequentially in PosDueTest.
 */

const POS_DUE_RACE_CONNECTION = 'pos_due_race';

function posDueRaceDatabase(): string
{
    return config('database.connections.mysql.database').'_pos_due_race';
}

beforeEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    $this->original = DB::getDefaultConnection();
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.posDueRaceDatabase().'`');
    DB::connection('mysql')->statement('CREATE DATABASE `'.posDueRaceDatabase().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    config(['database.connections.'.POS_DUE_RACE_CONNECTION => ['database' => posDueRaceDatabase()] + config('database.connections.mysql')]);
    DB::setDefaultConnection(POS_DUE_RACE_CONNECTION);
    Artisan::call('migrate', ['--database' => POS_DUE_RACE_CONNECTION, '--force' => true]);
});

afterEach(function (): void {
    if (onlyOnMysql()) {
        return;
    }
    DB::purge(POS_DUE_RACE_CONNECTION);
    DB::setDefaultConnection($this->original);
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.posDueRaceDatabase().'`');
});

it('lets only one of two concurrent due sales through the customer limit', function (): void {
    expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');

    (new RolesAndPermissionsSeeder)->run();
    Setting::put(PosDueSettings::KEY, '1000.00', SettingGroup::Checkout);
    $branch = PosKit::branch();
    $variant = PosKit::variant($branch, 10, '600.00', '300.00');
    Customer::query()->create(['phone' => '+8801819552310', 'name' => 'Rahima Akter', 'source' => CustomerSource::WalkIn, 'preferred_language' => 'bn']);

    $inputs = [];
    foreach ([1, 2] as $i) {
        $cashier = User::factory()->withRole(Role::Manager)->create();
        $session = PosKit::open($branch, $cashier, '0.00', 'Register '.$i);
        $inputs[] = new SaleData(
            sessionId: $session->id,
            lines: [$variant->id => 1],
            tenders: [new TenderInput(CounterTender::Due, Money::of('600'))],
            expectedTotal: Money::of('600'),
            cashierId: $cashier->id,
            phone: PhoneNumber::parse(PosKit::PHONE),
            idempotencyKey: 'race-'.$i,
            canSellOnDue: true,
        );
    }

    $dir = sys_get_temp_dir().'/pos-due-race-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $go = $dir.'/go';
    DB::disconnect(POS_DUE_RACE_CONNECTION);

    $pids = [];
    foreach ($inputs as $i => $input) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $deadline = microtime(true) + 10;
            while (! file_exists($go) && microtime(true) < $deadline) {
                usleep(1000);
            }
            try {
                app(ChargeSale::class)->handle($input);
                $result = 'sold';
            } catch (ValidationException) {
                $result = 'over_limit';
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

    $tally = array_count_values($results);
    ksort($tally);
    expect($tally)->toBe(['over_limit' => 1, 'sold' => 1])
        ->and(Order::query()->count())->toBe(1)
        ->and((string) Order::query()->sole()->due_total)->toBe('600.00');
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel due sales need MySQL row locking (make test-mysql).');
