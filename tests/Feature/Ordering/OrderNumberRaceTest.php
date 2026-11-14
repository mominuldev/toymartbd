<?php

declare(strict_types=1);

use App\Domain\Ordering\Services\OrderNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\ForkRace;

/*
 * Order numbers under concurrency (P9, LOAD-AND-CONCURRENCY F-1). The counter row used to
 * be INSERT IGNOREd unconditionally inside the checkout transaction: on an existing row
 * that takes a shared lock, and two checkouts that both hold it deadlock upgrading to
 * FOR UPDATE. PlaceOrder's 3× retry hid it as wasted work (and, under a burst, as a 500
 * after the third loss). The fix: create the row before the transaction (prepare()), and
 * inside it lock first and insert only when the row is missing.
 *
 * Every transaction below runs with ONE attempt — no retry — so a single deadlock fails
 * the test. MySQL only (CI's mysql group); the SQLite tests at the bottom prove
 * sequencing, the Dhaka year and rollback.
 */

const ORDER_RACE_CHILDREN = 12;
const ORDER_RACE_PER_CHILD = 5;

/** @return Closure(): string a child that draws numbers in its own transactions; every third one rolls back */
function orderNumberChild(int $child, bool $prepare): Closure
{
    return static function () use ($child, $prepare): string {
        $kept = [];
        for ($n = 0; $n < ORDER_RACE_PER_CHILD; $n++) {
            if ($prepare) {
                app(OrderNumberGenerator::class)->prepare();
            }
            $rollback = ($child + $n) % 3 === 0;
            try {
                DB::transaction(static function () use (&$kept, $rollback): void {
                    $number = app(OrderNumberGenerator::class)->next();
                    usleep(2000); // the rest of a checkout, holding the counter lock
                    if ($rollback) {
                        throw new RuntimeException('rolled back');
                    }
                    $kept[] = $number;
                }, 1);
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'rolled back') {
                    throw $e;
                }
            }
        }

        return implode(',', $kept);
    };
}

/** @param  list<string>  $results */
function assertContiguousOrderNumbers(array $results): void
{
    expect(array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'error') || $r === 'no result')))->toBe([]);
    $numbers = array_values(array_filter(explode(',', implode(',', $results))));
    $year = (int) CarbonImmutable::now('Asia/Dhaka')->format('y');
    $sequences = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1), $numbers);
    sort($sequences);

    expect($numbers)->each->toStartWith(sprintf('TMB-%02d-', $year))
        // Rolled-back draws returned their numbers: committed numbers are exactly 1..n.
        ->and($sequences)->toBe(range(1, count($numbers)))
        ->and((int) DB::table('order_number_sequences')->value('last_value'))->toBe(count($numbers));
}

describe('on MySQL', function (): void {
    beforeEach(function (): void {
        if (onlyOnMysql()) {
            return;
        }
        ForkRace::open('order_number_race');
    });

    afterEach(function (): void {
        if (onlyOnMysql()) {
            return;
        }
        ForkRace::close();
    });

    it('never deadlocks on an existing counter row, without retries', function (): void {
        expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');
        DB::table('order_number_sequences')->insert(['year' => (int) CarbonImmutable::now('Asia/Dhaka')->format('Y'), 'last_value' => 0]);
        $before = ForkRace::deadlocks();

        $results = ForkRace::run(array_map(static fn (int $i): Closure => orderNumberChild($i, prepare: false), range(0, ORDER_RACE_CHILDREN - 1)));

        assertContiguousOrderNumbers($results);
        expect(ForkRace::deadlocks() - $before)->toBe(0);
    });

    it('creates the first counter row of a year once when every checkout prepares it at the same moment', function (): void {
        expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');
        $before = ForkRace::deadlocks();

        $results = ForkRace::run(array_map(static fn (int $i): Closure => orderNumberChild($i, prepare: true), range(0, ORDER_RACE_CHILDREN - 1)));

        assertContiguousOrderNumbers($results);
        expect(DB::table('order_number_sequences')->count())->toBe(1)
            ->and(ForkRace::deadlocks() - $before)->toBe(0);
    });
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel checkouts need MySQL row locking (make test-mysql).');

it('prepares the counter row outside the transaction, idempotently, for the Dhaka year', function (): void {
    $generator = app(OrderNumberGenerator::class);
    // 31 Dec 19:00 UTC is already 1 Jan 2027 in Dhaka.
    $generator->prepare(CarbonImmutable::parse('2026-12-31 19:00', 'UTC'));
    $generator->prepare(CarbonImmutable::parse('2026-12-31 19:00', 'UTC'));

    expect(DB::table('order_number_sequences')->pluck('last_value', 'year')->all())->toBe([2027 => 0]);
});

it('takes the next number from a prepared row and still creates a missing one inside the transaction', function (): void {
    $generator = app(OrderNumberGenerator::class);
    $generator->prepare(CarbonImmutable::parse('2026-06-01 10:00', 'UTC'));

    $numbers = DB::transaction(fn (): array => [
        $generator->next(CarbonImmutable::parse('2026-06-01 10:00', 'UTC')),
        // Not prepared: the year turned between prepare() and next() — the insert path.
        $generator->next(CarbonImmutable::parse('2027-03-01 10:00', 'UTC')),
    ]);

    expect($numbers)->toBe(['TMB-26-00001', 'TMB-27-00001']);
});

it('returns a rolled-back number, so the series has no gap', function (): void {
    $generator = app(OrderNumberGenerator::class);
    $generator->prepare();
    DB::transaction(fn (): string => $generator->next());

    try {
        DB::transaction(function () use ($generator): void {
            $generator->next();
            throw new RuntimeException('checkout refused');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn (): string => $generator->next()))->toEndWith('-00002');
});
