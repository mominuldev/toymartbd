<?php

declare(strict_types=1);

use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Models\Order;
use App\Domain\Risk\Actions\RefreshRiskProfile;
use App\Domain\Risk\Listeners\RefreshProfileOnOrderChange;
use App\Domain\Risk\Models\CustomerRiskProfile;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Risk\RiskKit;

/*
 * Concurrent profile rebuilds (follow-up from P7 batch 1): two after-commit listeners for the
 * same brand-new phone both find no row under FOR UPDATE and both insert. The loser hits the
 * `phone` unique index (or, on MySQL, a gap-lock deadlock) and must be retried — never lost,
 * never duplicated, never double counted.
 */

/** Simulates the other worker committing its insert between our SELECT … FOR UPDATE and INSERT. */
function raceAnotherWorkerOnFirstInsert(string $phone): object
{
    $state = new class
    {
        public int $creates = 0;
    };
    CustomerRiskProfile::creating(function (CustomerRiskProfile $p) use ($phone, $state): void {
        if ($p->phone !== $phone) {
            return; // the order's customer phone gets its own profile
        }
        if (++$state->creates === 1) {
            DB::table('customer_risk_profiles')->insert([
                'phone' => $phone, 'total_orders' => 99, 'status' => 'normal',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    return $state;
}

it('retries a rebuild that lost the duplicate-insert race, then updates the winner row', function (): void {
    $order = Order::factory()->create(['shipping_phone' => RiskKit::PHONE, 'shipping_name' => 'রাহেলা খাতুন']);
    $state = raceAnotherWorkerOnFirstInsert(RiskKit::PHONE);

    app(RefreshProfileOnOrderChange::class)->handle(new OrderPlaced($order->id, $order->customer_id));

    $profile = CustomerRiskProfile::query()->where('phone', RiskKit::PHONE)->sole();
    // On one SQLite connection the racer's insert rolls back with our savepoint, so the
    // retry inserts again; on MySQL (test below) the retry finds and locks the winner's row.
    expect($state->creates)->toBe(2)                 // the unique violation was retried
        ->and($profile->total_orders)->toBe(1)       // recomputed from orders
        ->and($profile->score_computed_at)->not->toBeNull();
});

it('retries a deadlock victim and still writes exactly one profile', function (): void {
    $order = Order::factory()->create(['shipping_phone' => RiskKit::PHONE, 'shipping_name' => 'রাহেলা খাতুন']);
    $calls = 0;
    CustomerRiskProfile::saving(function () use (&$calls): void {
        if (++$calls === 1) {
            throw new QueryException('mysql', 'insert into customer_risk_profiles', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }
    });

    app(RefreshRiskProfile::class)->handle(RiskKit::PHONE);

    expect($calls)->toBe(2)
        ->and(CustomerRiskProfile::query()->where('phone', RiskKit::PHONE)->sole()->total_orders)->toBe(1);
});

it('gives up after a bounded number of attempts instead of looping forever', function (): void {
    $calls = 0;
    CustomerRiskProfile::saving(function () use (&$calls): void {
        $calls++;
        throw new UniqueConstraintViolationException('sqlite', 'insert into customer_risk_profiles', [], new PDOException('UNIQUE constraint failed: customer_risk_profiles.phone'));
    });

    expect(fn () => app(RefreshRiskProfile::class)->handle(RiskKit::PHONE))
        ->toThrow(UniqueConstraintViolationException::class);
    expect($calls)->toBe(RefreshRiskProfile::ATTEMPTS);
});

/*
 * The real race on MySQL: committed fixtures in a throwaway database, 8 forked workers
 * rebuilding the same new phone behind a start barrier (pattern: CheckoutConcurrencyTest).
 */
const RISK_RACE_CONNECTION = 'risk_race';

function riskRaceDatabase(): string
{
    return config('database.connections.mysql.database').'_risk_race';
}

it('lets 8 parallel rebuilds of one new phone all succeed with one profile row', function (): void {
    $original = DB::getDefaultConnection();
    DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.riskRaceDatabase().'`');
    DB::connection('mysql')->statement('CREATE DATABASE `'.riskRaceDatabase().'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
    config(['database.connections.'.RISK_RACE_CONNECTION => ['database' => riskRaceDatabase()] + config('database.connections.mysql')]);
    DB::setDefaultConnection(RISK_RACE_CONNECTION);

    try {
        Artisan::call('migrate', ['--database' => RISK_RACE_CONNECTION, '--force' => true]);
        Order::factory()->count(2)->create(['shipping_phone' => RiskKit::PHONE, 'shipping_name' => 'রাহেলা খাতুন']);

        $dir = sys_get_temp_dir().'/risk-race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $go = $dir.'/go';
        DB::disconnect(RISK_RACE_CONNECTION);

        $pids = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $deadline = microtime(true) + 10;
                while (! file_exists($go) && microtime(true) < $deadline) {
                    usleep(500);
                }
                try {
                    app(RefreshRiskProfile::class)->handle(RiskKit::PHONE);
                    $result = 'ok';
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
        for ($i = 0; $i < 8; $i++) {
            $results[] = is_file($dir.'/'.$i) ? (string) file_get_contents($dir.'/'.$i) : 'no result';
            @unlink($dir.'/'.$i);
        }
        @unlink($go);
        @rmdir($dir);

        expect(array_count_values($results))->toBe(['ok' => 8]);
        $profile = CustomerRiskProfile::query()->where('phone', RiskKit::PHONE)->sole();
        expect($profile->total_orders)->toBe(2);
    } finally {
        DB::purge(RISK_RACE_CONNECTION);
        DB::setDefaultConnection($original);
        DB::connection('mysql')->statement('DROP DATABASE IF EXISTS `'.riskRaceDatabase().'`');
    }
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel rebuilds need MySQL row locking (make test-mysql).');
