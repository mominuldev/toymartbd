<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\AdjustStock;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\DTOs\StockAdjustmentData;
use App\Domain\Inventory\DTOs\StockAdjustmentLine;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockValuation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
 * AdjustStock — the manual stock change use case: one transaction, ordered locks, the
 * adjustment document + lines + one ledger movement per line, never below reserved.
 */

function adjustOpen(int $variantId, int $quantity): void
{
    DB::transaction(fn () => app(StockLedger::class)->recordOne($variantId, StockMovementType::OpeningBalance, $quantity));
}

/** @param  list<StockAdjustmentLine>  $lines */
function adjustRun(StockAdjustmentReason $reason, array $lines, ?string $note = null): StockAdjustment
{
    return app(AdjustStock::class)->handle(new StockAdjustmentData($reason, $lines, note: $note));
}

function adjustOnHand(int $variantId): int
{
    return (int) StockLevel::query()->where('variant_id', $variantId)->value('on_hand');
}

/** §7.1 for every (variant, location). */
function adjustLedgerBalanced(): bool
{
    foreach (StockLevel::all() as $level) {
        $sum = (int) StockMovement::query()->where('variant_id', $level->variant_id)->where('location_id', $level->location_id)->sum('quantity');
        if ($sum !== $level->on_hand) {
            return false;
        }
    }

    return true;
}

beforeEach(function (): void {
    $this->variant = ProductVariant::factory()->create(['cost_price' => '310.00']);
    adjustOpen($this->variant->id, 10);
});

it('records a damage adjustment as a damage_out movement with its document and line', function (): void {
    $adjustment = adjustRun(StockAdjustmentReason::Damage, [new StockAdjustmentLine($this->variant->id, quantity: -2)], 'Crushed in transit');

    $movement = StockMovement::query()->where('type', StockMovementType::DamageOut)->sole();
    expect(adjustOnHand($this->variant->id))->toBe(8)
        ->and($adjustment->number)->toMatch('/^ADJ-\d{4}-\d{4}$/')
        ->and($adjustment->items)->toHaveCount(1)
        ->and($adjustment->items[0]->quantity_before)->toBe(10)
        ->and($adjustment->items[0]->quantity_after)->toBe(8)
        ->and($movement->quantity)->toBe(-2)
        ->and($movement->balance_after)->toBe(8)
        ->and($movement->reference_type)->toBe('stock_adjustment')
        ->and($movement->reference_id)->toBe($adjustment->id)
        ->and($movement->note)->toBe('Crushed in transit')
        // No purchase has set avg_cost yet, so the cost price is the basis.
        ->and($movement->unit_cost)->toBe('310.0000')
        ->and(adjustLedgerBalanced())->toBeTrue();
});

it('writes adjustment_in for found stock and adjustment_out for loss, expiry and gift/sample', function (StockAdjustmentReason $reason, int $change, StockMovementType $type): void {
    adjustRun($reason, [new StockAdjustmentLine($this->variant->id, quantity: $change)]);

    expect(StockMovement::query()->latest('id')->first()?->type)->toBe($type)
        ->and(adjustOnHand($this->variant->id))->toBe(10 + $change);
})->with([
    'found' => [StockAdjustmentReason::Found, 3, StockMovementType::AdjustmentIn],
    'loss' => [StockAdjustmentReason::Loss, -1, StockMovementType::AdjustmentOut],
    'expiry' => [StockAdjustmentReason::Expiry, -1, StockMovementType::AdjustmentOut],
    'gift/sample' => [StockAdjustmentReason::GiftSample, -1, StockMovementType::AdjustmentOut],
    'correction up' => [StockAdjustmentReason::Correction, 2, StockMovementType::AdjustmentIn],
    'correction down' => [StockAdjustmentReason::Correction, -2, StockMovementType::AdjustmentOut],
]);

it('turns a counted quantity into the change against the locked on_hand', function (): void {
    adjustRun(StockAdjustmentReason::Recount, [new StockAdjustmentLine($this->variant->id, counted: 7)]);

    expect(adjustOnHand($this->variant->id))->toBe(7)
        ->and(StockMovement::query()->latest('id')->first()?->quantity)->toBe(-3);
});

it('refuses a reason moving stock the wrong way, and changes nothing', function (StockAdjustmentReason $reason, int $change): void {
    expect(fn () => adjustRun($reason, [new StockAdjustmentLine($this->variant->id, quantity: $change)]))
        ->toThrow(ValidationException::class);

    expect(adjustOnHand($this->variant->id))->toBe(10)
        ->and(StockAdjustment::query()->count())->toBe(0)
        ->and(StockMovement::query()->count())->toBe(1);
})->with([
    'found cannot remove' => [StockAdjustmentReason::Found, -1],
    'damage cannot add' => [StockAdjustmentReason::Damage, 1],
    'gift/sample cannot add' => [StockAdjustmentReason::GiftSample, 1],
]);

it('refuses a count that matches what is on hand', function (): void {
    try {
        adjustRun(StockAdjustmentReason::Recount, [new StockAdjustmentLine($this->variant->id, counted: 10)]);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.counted');
    }
});

it('never takes on_hand below what is reserved for open orders', function (): void {
    app(ReserveStock::class)->handle([$this->variant->id => 6]);

    try {
        adjustRun(StockAdjustmentReason::Damage, [new StockAdjustmentLine($this->variant->id, quantity: -5)]);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.quantity');
    }

    // Down to exactly the reserved quantity is fine.
    adjustRun(StockAdjustmentReason::Damage, [new StockAdjustmentLine($this->variant->id, quantity: -4)]);
    expect(adjustOnHand($this->variant->id))->toBe(6);
});

it('is all or nothing across lines and reports the refused line by its index', function (): void {
    $other = ProductVariant::factory()->create();
    adjustOpen($other->id, 1);

    try {
        adjustRun(StockAdjustmentReason::Loss, [
            new StockAdjustmentLine($this->variant->id, quantity: -1),
            new StockAdjustmentLine($other->id, quantity: -2),
        ]);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['lines.1.quantity']);
    }

    expect(adjustOnHand($this->variant->id))->toBe(10)->and(adjustOnHand($other->id))->toBe(1);
});

it('adjusts several variants in one document, writing movements in ascending variant order', function (): void {
    $low = ProductVariant::factory()->create();
    adjustOpen($low->id, 5);
    [$first, $second] = $low->id < $this->variant->id ? [$low, $this->variant] : [$this->variant, $low];

    $adjustment = adjustRun(StockAdjustmentReason::Correction, [
        new StockAdjustmentLine($second->id, quantity: 1),
        new StockAdjustmentLine($first->id, quantity: -1),
    ]);

    $movements = StockMovement::query()->where('reference_id', $adjustment->id)->orderBy('id')->pluck('variant_id')->all();
    expect($movements)->toBe([$first->id, $second->id])->and(adjustLedgerBalanced())->toBeTrue();
});

it('creates the level row for a variant that never had stock', function (): void {
    $fresh = ProductVariant::factory()->create();

    adjustRun(StockAdjustmentReason::Found, [new StockAdjustmentLine($fresh->id, quantity: 4)]);

    expect(adjustOnHand($fresh->id))->toBe(4)->and(adjustLedgerBalanced())->toBeTrue();
});

it('announces the level change after commit', function (): void {
    Event::fake([StockLevelChanged::class]);

    adjustRun(StockAdjustmentReason::Damage, [new StockAdjustmentLine($this->variant->id, quantity: -1)]);

    Event::assertDispatched(StockLevelChanged::class, fn (StockLevelChanged $e): bool => $e->variantId === $this->variant->id);
});

it('values stock at average cost, falling back to the cost price', function (): void {
    expect(StockValuation::costBasis('0.0000', '310.00'))->toBe('310.0000')
        ->and(StockValuation::costBasis('1050.4000', '310.00'))->toBe('1050.4000')
        ->and(StockValuation::value(3, '1050.4050'))->toBe('3151.22')
        ->and(StockValuation::value(0, '99.99'))->toBe('0.00');
});
