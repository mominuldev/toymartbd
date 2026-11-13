<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Actions\RequestShipments;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\ShipmentNumberGenerator;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Support\CourierFixtures;
use Tests\Support\ForkRace;

/*
 * Shipment numbers under concurrency (P9, LOAD-AND-CONCURRENCY F-2). The same counter-row
 * deadlock as order numbers (OrderNumberRaceTest) — and RequestShipment had no retry, so
 * two staff bulk-sending at once got a 500 and, mid-way through a bulk request, lost the
 * outcomes of orders already requested. Fixed: the row is prepared before the transaction,
 * the transaction retries, and RequestShipments always dispatches what it committed.
 *
 * MySQL only for the races (CI's mysql group); the SQLite tests below prove sequencing,
 * the Dhaka year, rollback and the dispatch-on-failure path.
 */

describe('on MySQL', function (): void {
    beforeEach(function (): void {
        if (onlyOnMysql()) {
            return;
        }
        ForkRace::open('shipment_number_race');
    });

    afterEach(function (): void {
        if (onlyOnMysql()) {
            return;
        }
        ForkRace::close();
    });

    it('never deadlocks on the counter row, without retries', function (): void {
        expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');
        $before = ForkRace::deadlocks();

        $jobs = array_map(static fn (int $child): Closure => static function () use ($child): string {
            $kept = [];
            for ($n = 0; $n < 5; $n++) {
                app(ShipmentNumberGenerator::class)->prepare();
                try {
                    DB::transaction(static function () use (&$kept, $child, $n): void {
                        $number = app(ShipmentNumberGenerator::class)->next();
                        usleep(2000);
                        if (($child + $n) % 4 === 0) {
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
        }, range(0, 11));

        $results = ForkRace::run($jobs);

        expect(array_values(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'error') || $r === 'no result')))->toBe([]);
        $sequences = array_map(static fn (string $n): int => (int) substr($n, strrpos($n, '-') + 1), array_values(array_filter(explode(',', implode(',', $results)))));
        sort($sequences);
        expect($sequences)->toBe(range(1, count($sequences)))
            ->and((int) DB::table('shipment_number_sequences')->value('last_value'))->toBe(count($sequences))
            ->and(ForkRace::deadlocks() - $before)->toBe(0);
    });

    it('gives each order exactly one shipment when two staff bulk-send overlapping sets at once', function (): void {
        expect(function_exists('pcntl_fork'))->toBeTrue('pcntl is required for the race test');
        Queue::fake(); // children inherit it: booking is the job's business, not this race's
        CourierFixtures::install('steadfast');
        $variant = OrderFixtures::stockedVariant(100, '600.00', '350.00');
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = app(TransitionOrder::class)->handle(OrderFixtures::place([$variant->id => 1]), OrderStatus::Confirmed)->id;
        }
        $reversed = array_reverse($ids);

        // Four requesters: two the board's order, two reversed — every order is contested.
        $results = ForkRace::run(array_map(static fn (array $set): Closure => static function () use ($set): string {
            $tally = [];
            foreach (app(RequestShipments::class)->handle($set) as $outcome) {
                $tally[$outcome->result] = ($tally[$outcome->result] ?? 0) + 1;
            }
            ksort($tally);

            return json_encode($tally, JSON_THROW_ON_ERROR);
        }, [$ids, $reversed, $ids, $reversed]));

        expect(array_values(array_filter($results, static fn (string $r): bool => ! str_starts_with($r, '{'))))->toBe([]);
        $requested = array_sum(array_map(static fn (string $r): int => json_decode($r, true, 512, JSON_THROW_ON_ERROR)['requested'] ?? 0, $results));
        $conflicts = array_sum(array_map(static fn (string $r): int => json_decode($r, true, 512, JSON_THROW_ON_ERROR)['conflict'] ?? 0, $results));

        expect($requested)->toBe(8)
            ->and($conflicts)->toBe(24)
            ->and(Shipment::query()->count())->toBe(8)
            ->and(Shipment::query()->distinct()->count('order_id'))->toBe(8)
            ->and(Shipment::query()->distinct()->count('number'))->toBe(8)
            ->and((int) DB::table('shipment_number_sequences')->value('last_value'))->toBe(8);
    });
})->group('mysql')->skip(onlyOnMysql(...), 'Parallel shipment requests need MySQL row locking (make test-mysql).');

it('numbers shipments SHP-YY-NNNNNN per Dhaka year, from a prepared row or a missing one', function (): void {
    $generator = app(ShipmentNumberGenerator::class);
    $generator->prepare(CarbonImmutable::parse('2026-06-01 10:00', 'UTC'));
    $generator->prepare(CarbonImmutable::parse('2026-06-01 10:00', 'UTC'));
    expect(DB::table('shipment_number_sequences')->count())->toBe(1);

    $numbers = DB::transaction(fn (): array => [
        $generator->next(CarbonImmutable::parse('2026-06-01 10:00', 'UTC')),
        $generator->next(CarbonImmutable::parse('2026-06-01 10:05', 'UTC')),
        // 31 Dec 19:00 UTC is 1 Jan in Dhaka, and that year was never prepared.
        $generator->next(CarbonImmutable::parse('2026-12-31 19:00', 'UTC')),
    ]);

    expect($numbers)->toBe(['SHP-26-000001', 'SHP-26-000002', 'SHP-27-000001']);
});

it('returns a rolled-back shipment number, so the series has no gap', function (): void {
    $generator = app(ShipmentNumberGenerator::class);
    $generator->prepare();
    try {
        DB::transaction(function () use ($generator): void {
            $generator->next();
            throw new RuntimeException('refused');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn (): string => $generator->next()))->toEndWith('-000001');
});

it('still books the shipments it created when a later order in the bulk request throws', function (): void {
    Queue::fake();
    CourierFixtures::install('steadfast');
    $variant = OrderFixtures::stockedVariant(10, '600.00', '350.00');
    $first = app(TransitionOrder::class)->handle(OrderFixtures::place([$variant->id => 1]), OrderStatus::Confirmed);
    $second = app(TransitionOrder::class)->handle(OrderFixtures::place([$variant->id => 1]), OrderStatus::Confirmed);

    // The second order's request hits an unexpected database error (not a "not requestable" refusal).
    $calls = 0;
    DB::listen(function ($query) use (&$calls): void {
        if (str_contains($query->sql, 'insert into "shipments"') && ++$calls === 2) {
            throw new RuntimeException('connection lost');
        }
    });

    expect(fn () => app(RequestShipments::class)->handle([$first->id, $second->id]))->toThrow(RuntimeException::class, 'connection lost');

    Queue::assertPushed(App\Domain\Fulfilment\Jobs\BookShipmentsBulk::class, fn ($job): bool => $job->shipmentIds === [Shipment::query()->where('order_id', $first->id)->value('id')]);
});
