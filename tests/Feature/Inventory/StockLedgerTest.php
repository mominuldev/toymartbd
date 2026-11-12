<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\ReleaseStock;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\StockLedgerViolation;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockAvailability;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockReservations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** Record movements through the ledger inside a transaction, as an Action would. */
function stockIn(int $variantId, int $quantity, StockMovementType $type = StockMovementType::OpeningBalance): void
{
    DB::transaction(fn () => app(StockLedger::class)->recordOne($variantId, $type, $quantity));
}

/** §7.1 invariant: SUM(stock_movements.quantity) = stock_levels.on_hand for every (variant, location). */
function assertLedgerMatchesLevels(): void
{
    $sums = StockMovement::query()->groupBy('variant_id', 'location_id')
        ->select('variant_id', 'location_id', DB::raw('SUM(quantity) as total'))->get()
        ->mapWithKeys(fn ($r) => ["{$r->variant_id}:{$r->location_id}" => (int) $r->total]);

    foreach (StockLevel::all() as $level) {
        expect($sums["{$level->variant_id}:{$level->location_id}"] ?? 0)->toBe($level->on_hand);
    }
}

beforeEach(function (): void {
    $this->variant = ProductVariant::factory()->create();
});

it('seeds exactly one default stock location', function (): void {
    expect(StockLocation::query()->count())->toBe(1)
        ->and(StockLocation::defaultId())->toBe(StockLocation::query()->value('id'));
});

it('records a movement and moves the level by the same signed amount', function (): void {
    stockIn($this->variant->id, 10);
    DB::transaction(fn () => app(StockLedger::class)->recordOne($this->variant->id, StockMovementType::DamageOut, 3, note: 'Crushed box'));

    $level = StockLevel::query()->where('variant_id', $this->variant->id)->sole();
    $movements = StockMovement::query()->where('variant_id', $this->variant->id)->orderBy('id')->get();

    expect($level->on_hand)->toBe(7)
        ->and($movements->pluck('quantity')->all())->toBe([10, -3])
        ->and($movements->pluck('balance_after')->all())->toBe([10, 7])
        ->and($movements[1]->type)->toBe(StockMovementType::DamageOut)
        ->and($movements[1]->note)->toBe('Crushed box');
    assertLedgerMatchesLevels();
});

it('never lets on_hand go negative and writes nothing for a refused batch', function (): void {
    $other = ProductVariant::factory()->create();
    stockIn($this->variant->id, 2);
    stockIn($other->id, 5);

    $attempt = fn () => DB::transaction(fn () => app(StockLedger::class)->record([
        new StockMovementLine($other->id, StockMovementType::AdjustmentOut, 1),
        new StockMovementLine($this->variant->id, StockMovementType::AdjustmentOut, 3),
    ]));

    expect($attempt)->toThrow(InsufficientStock::class);

    try {
        $attempt();
    } catch (InsufficientStock $e) {
        expect($e->lines)->toBe([$this->variant->id => ['requested' => 3, 'available' => 2]])
            ->and($e->getStatusCode())->toBe(409);
    }

    expect(StockMovement::query()->count())->toBe(2)
        ->and(app(StockAvailability::class)->forVariants([$this->variant->id, $other->id]))
        ->toBe([$this->variant->id => 2, $other->id => 5]);
    assertLedgerMatchesLevels();
});

it('checks a batch against the running balance of the same variant', function (): void {
    stockIn($this->variant->id, 3);

    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->record([
        new StockMovementLine($this->variant->id, StockMovementType::DamageOut, 2),
        new StockMovementLine($this->variant->id, StockMovementType::DamageOut, 2),
    ])))->toThrow(InsufficientStock::class);

    expect(StockLevel::query()->value('on_hand'))->toBe(3);
});

// The outside-a-transaction guard cannot be exercised here: RefreshDatabase runs every
// test inside an open transaction. It is a one-line DB::transactionLevel() check.
it('refuses non-positive quantities', function (): void {
    expect(fn () => DB::transaction(fn () => app(StockLedger::class)->recordOne($this->variant->id, StockMovementType::PurchaseIn, 0)))
        ->toThrow(StockLedgerViolation::class)
        ->and(fn () => DB::transaction(fn () => app(StockReservations::class)->reserve([$this->variant->id => -1])))
        ->toThrow(StockLedgerViolation::class);
});

it('keeps stock_movements append-only', function (): void {
    stockIn($this->variant->id, 4);
    $movement = StockMovement::query()->sole();

    expect(fn () => $movement->update(['quantity' => 40]))->toThrow(StockLedgerViolation::class)
        ->and(fn () => $movement->delete())->toThrow(StockLedgerViolation::class)
        ->and(StockMovement::query()->sole()->quantity)->toBe(4);
});

it('reserves against available = on_hand - reserved and releases back', function (): void {
    stockIn($this->variant->id, 5);

    app(ReserveStock::class)->handle([$this->variant->id => 3]);
    $level = StockLevel::query()->sole();
    expect($level->on_hand)->toBe(5)->and($level->reserved)->toBe(3)->and($level->available())->toBe(2)
        ->and(app(StockAvailability::class)->forVariants([$this->variant->id]))->toBe([$this->variant->id => 2]);

    expect(fn () => app(ReserveStock::class)->handle([$this->variant->id => 3]))->toThrow(InsufficientStock::class);

    app(ReleaseStock::class)->handle([$this->variant->id => 3]);
    expect(StockLevel::query()->sole()->reserved)->toBe(0)
        ->and(fn () => app(ReleaseStock::class)->handle([$this->variant->id => 1]))->toThrow(StockLedgerViolation::class);

    // Reserving writes no movement: the ledger still sums to on_hand.
    expect(StockMovement::query()->count())->toBe(1);
    assertLedgerMatchesLevels();
});

it('cannot oversell: many competing reservations never exceed on_hand', function (): void {
    $other = ProductVariant::factory()->create();
    stockIn($this->variant->id, 7);
    stockIn($other->id, 100);

    $won = 0;
    $lost = 0;
    // Twelve "checkouts" each wanting one of the scarce variant plus one of the plentiful
    // one, alternating line order — each runs as its own transaction, as concurrent
    // requests would. All-or-nothing: a lost checkout holds neither line.
    foreach (range(1, 12) as $i) {
        $lines = $i % 2 === 0 ? [$this->variant->id => 1, $other->id => 1] : [$other->id => 1, $this->variant->id => 1];
        try {
            app(ReserveStock::class)->handle($lines);
            $won++;
        } catch (InsufficientStock) {
            $lost++;
        }
    }

    $scarce = StockLevel::query()->where('variant_id', $this->variant->id)->sole();
    expect($won)->toBe(7)->and($lost)->toBe(5)
        ->and($scarce->reserved)->toBe(7)->and($scarce->available())->toBe(0)
        ->and(StockLevel::query()->where('variant_id', $other->id)->value('reserved'))->toBe(7);
});

it('holds a row lock so a second connection cannot reserve the same stock concurrently', function (): void {
    stockIn($this->variant->id, 1);
    $config = config('database.connections.mysql');
    config(['database.connections.mysql_second' => $config]);
    $second = DB::connection('mysql_second');
    $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

    // RefreshDatabase keeps the default connection inside an open transaction, so the level
    // row and its lock belong to it; the second connection's locking read must wait.
    DB::transaction(function () use ($second): void {
        app(StockReservations::class)->reserve([$this->variant->id => 1]);

        // While this transaction holds the lock, the other connection's FOR UPDATE waits and times out.
        expect(fn () => $second->transaction(fn () => $second->table('stock_levels')
            ->where('variant_id', $this->variant->id)->lockForUpdate()->get()))
            ->toThrow(Illuminate\Database\QueryException::class);
    });
})->group('mysql')->skip(onlyOnMysql(...), 'Row locking is only meaningful on MySQL (make test-mysql).');

it('dispatches StockLevelChanged after commit, once per variant, and not on rollback', function (): void {
    Event::fake([StockLevelChanged::class]);
    $product = Product::factory()->create();
    $a = ProductVariant::factory()->for($product)->create();
    $b = ProductVariant::factory()->for($product)->create();

    DB::transaction(fn () => app(StockLedger::class)->record([
        new StockMovementLine($b->id, StockMovementType::PurchaseIn, 2),
        new StockMovementLine($a->id, StockMovementType::PurchaseIn, 2),
    ]));

    Event::assertDispatchedTimes(StockLevelChanged::class, 2);
    Event::assertDispatched(StockLevelChanged::class, fn (StockLevelChanged $e) => $e->variantId === $a->id && $e->productId === $product->id);

    try {
        DB::transaction(function () use ($a): void {
            app(StockReservations::class)->reserve([$a->id => 1]);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Event::assertDispatchedTimes(StockLevelChanged::class, 2);
});

it('reports availability: missing rows are 0 and in-stock products need an active variant with stock', function (): void {
    $inStock = Product::factory()->create();
    $soldOut = Product::factory()->create();
    $archivedOnly = Product::factory()->create();
    $noRows = Product::factory()->create();

    $v1 = ProductVariant::factory()->for($inStock)->create();
    $v2 = ProductVariant::factory()->for($soldOut)->create();
    $v3 = ProductVariant::factory()->for($archivedOnly)->create(['status' => VariantStatus::Archived]);
    $v4 = ProductVariant::factory()->for($noRows)->create();

    stockIn($v1->id, 2);
    stockIn($v2->id, 1);
    stockIn($v3->id, 9);
    app(ReserveStock::class)->handle([$v2->id => 1]);

    $availability = app(StockAvailability::class);

    expect($availability->forVariants([$v1->id, $v2->id, $v3->id, $v4->id]))
        ->toBe([$v1->id => 2, $v2->id => 0, $v3->id => 9, $v4->id => 0])
        ->and($availability->forVariants([]))->toBe([])
        ->and($availability->inStockProductIds([$inStock->id, $soldOut->id, $archivedOnly->id, $noRows->id]))
        ->toBe([$inStock->id]);
});
