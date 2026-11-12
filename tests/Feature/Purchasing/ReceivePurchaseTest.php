<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Purchasing\Actions\CancelPurchase;
use App\Domain\Purchasing\Actions\UpdatePurchase;
use App\Domain\Purchasing\DTOs\PurchaseData;
use App\Domain\Purchasing\DTOs\PurchaseLineData;
use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Events\PurchaseReceived;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\PurchaseReceiptItem;
use App\Domain\Purchasing\Services\ReceiptCalculator;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Purchasing\PurchasingKit as Kit;

/*
 * ReceivePurchase — DATABASE-DESIGN §4.6: partial receipt, landed-cost apportionment,
 * weighted-average costing under the ordered row locks, and the stock invariant (§7.1).
 */

beforeEach(function (): void {
    $this->puzzle = ProductVariant::factory()->create(['cost_price' => '300.00', 'weight_grams' => 400]);
    $this->doll = ProductVariant::factory()->create(['cost_price' => '1400.00', 'weight_grams' => 900]);
});

it('receives a partial delivery, splits freight by value and steps the weighted average', function (): void {
    Kit::open($this->puzzle->id, 10); // 10 on hand valued at cost_price 300
    $po = Kit::purchase([[$this->puzzle, 30, '298'], [$this->doll, 12, '1420']]);
    [$puzzleLine, $dollLine] = $po->items->all();

    $receipt = Kit::receive($po, [$puzzleLine->id => 30, $dollLine->id => 10], freight: '2400');

    // goods: 30 × 298 = 8,940 and 10 × 1,420 = 14,200 → 23,140; freight 2,400 by value
    $items = $receipt->items->keyBy('variant_id');
    expect($receipt->number)->toMatch('/^GRN-\d{4}-\d{4}$/')
        ->and($receipt->goods_value->toDecimal())->toBe('23140.00')
        ->and($receipt->payable_amount->toDecimal())->toBe('25540.00')
        ->and($items[$this->puzzle->id]->line_value->toDecimal())->toBe('8940.00')
        ->and($items[$this->puzzle->id]->allocated_cost->toDecimal())->toBe('927.23')
        ->and($items[$this->doll->id]->allocated_cost->toDecimal())->toBe('1472.77')
        ->and($items[$this->puzzle->id]->allocated_cost->plus($items[$this->doll->id]->allocated_cost)->toDecimal())->toBe('2400.00')
        // (8,940 + 927.23) / 30 = 328.9077
        ->and($items[$this->puzzle->id]->landed_unit_cost)->toBe('328.9077')
        // (10 × 300 + 30 × 328.9077) / 40 = 321.6808 (half-up)
        ->and($items[$this->puzzle->id]->avg_cost_before)->toBe('300.0000')
        ->and($items[$this->puzzle->id]->avg_cost_after)->toBe('321.6808')
        // doll had no stock: the average is its landed cost (14,200 + 1,472.77) / 10
        ->and($items[$this->doll->id]->avg_cost_after)->toBe('1567.2770');

    expect(Kit::level($this->puzzle->id)->on_hand)->toBe(40)
        ->and(Kit::avg($this->puzzle->id))->toBe('321.6808')
        ->and($this->puzzle->refresh()->cost_price->toDecimal())->toBe('321.68')
        ->and($this->doll->refresh()->cost_price->toDecimal())->toBe('1567.28');

    $movement = StockMovement::query()->where('variant_id', $this->puzzle->id)->where('type', StockMovementType::PurchaseIn)->sole();
    expect($movement->quantity)->toBe(30)
        ->and($movement->balance_after)->toBe(40)
        ->and($movement->unit_cost)->toBe('328.9077')
        ->and($movement->reference_type)->toBe('purchase_receipt')
        ->and($movement->reference_id)->toBe($receipt->id);

    $po->refresh()->load('items');
    expect($po->status)->toBe(PurchaseStatus::PartiallyReceived)
        ->and($po->items[1]->quantity_received)->toBe(10)
        ->and($po->items[1]->quantityOutstanding())->toBe(2)
        ->and($po->shipping_cost->toDecimal())->toBe('2400.00')
        ->and($po->received_value->toDecimal())->toBe('25540.00')
        ->and($po->due_amount->toDecimal())->toBe('25540.00')
        ->and(Kit::ledgerBalanced())->toBeTrue();
});

it('completes the PO on the last receipt, and the line values sum to its line total exactly', function (): void {
    // 3 × 333.3333 = 999.9999 → line total 1,000.00; received 1 + 1 + 1
    $po = Kit::purchase([[$this->puzzle, 3, '333.3333']]);
    $line = $po->items[0];

    Kit::receive($po, [$line->id => 1]);
    Kit::receive($po, [$line->id => 1]);
    Kit::receive($po->refresh(), [$line->id => 1]);

    $values = PurchaseReceiptItem::query()->where('purchase_item_id', $line->id)->orderBy('id')->get()->map(fn ($i) => $i->line_value->toDecimal())->all();
    expect($line->refresh()->line_total->toDecimal())->toBe('1000.00')
        ->and($values)->toBe(['333.33', '333.33', '333.34'])
        ->and($po->refresh()->status)->toBe(PurchaseStatus::Received)
        ->and($po->received_at)->not->toBeNull()
        ->and(Kit::level($this->puzzle->id)->on_hand)->toBe(3)
        ->and(Kit::ledgerBalanced())->toBeTrue();
});

it('splits customs and other costs too, by quantity or weight when asked', function (LandedCostMethod $method, string $puzzleShare, string $dollShare): void {
    $po = Kit::purchase([[$this->puzzle, 10, '100'], [$this->doll, 10, '1000']]);
    [$a, $b] = $po->items->all();

    $receipt = Kit::receive($po, [$a->id => 10, $b->id => 10], freight: '500', customs: '700', other: '100', method: $method);
    $items = $receipt->items->keyBy('variant_id');

    expect($items[$this->puzzle->id]->allocated_cost->toDecimal())->toBe($puzzleShare)
        ->and($items[$this->doll->id]->allocated_cost->toDecimal())->toBe($dollShare)
        ->and($receipt->apportion_method)->toBe($method);
})->with([
    'by value' => [LandedCostMethod::Value, '118.18', '1181.82'],
    'by quantity' => [LandedCostMethod::Quantity, '650.00', '650.00'],
    // 400 g × 10 vs 900 g × 10
    'by weight' => [LandedCostMethod::Weight, '400.00', '900.00'],
]);

it('refuses by-weight apportioning when the variants have no weight', function (): void {
    $light = ProductVariant::factory()->create(['weight_grams' => 0]);
    $po = Kit::purchase([[$light, 2, '50']]);

    Kit::receive($po, [$po->items[0]->id => 2], freight: '10', method: LandedCostMethod::Weight);
})->throws(ValidationException::class);

it('refuses an over-receipt and writes nothing', function (): void {
    $po = Kit::purchase([[$this->puzzle, 5, '100']]);
    $line = $po->items[0];
    Kit::receive($po, [$line->id => 3]);

    try {
        Kit::receive($po->refresh(), [$line->id => 3]);
        $this->fail('over-receipt accepted');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('lines.0.quantity');
    }

    expect($line->refresh()->quantity_received)->toBe(3)
        ->and(Kit::level($this->puzzle->id)->on_hand)->toBe(3)
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(Kit::ledgerBalanced())->toBeTrue();
});

it('refuses a receipt with no units, or a line from another PO', function (): void {
    $po = Kit::purchase([[$this->puzzle, 5, '100']]);
    $other = Kit::purchase([[$this->doll, 1, '100']]);

    expect(fn () => Kit::receive($po, [$po->items[0]->id => 0]))->toThrow(ValidationException::class)
        ->and(fn () => Kit::receive($po, [$other->items[0]->id => 1]))->toThrow(ValidationException::class);
});

it('receives only an ordered or partially received PO', function (): void {
    $draft = Kit::purchase([[$this->puzzle, 5, '100']], ordered: false);

    Kit::receive($draft, [$draft->items[0]->id => 1]);
})->throws(PurchaseConflict::class);

it('restarts the average at the landed cost when on hand is zero or negative', function (): void {
    // A negative balance cannot be reached through the ledger at MVP; force one to prove
    // the receipt neither divides by zero nor inflates the average.
    Kit::open($this->puzzle->id, 1);
    DB::table('stock_movements')->insert([
        'variant_id' => $this->puzzle->id, 'location_id' => StockLocation::defaultId(), 'type' => 'adjustment_out',
        'quantity' => -3, 'balance_after' => -2, 'created_at' => now(),
    ]);
    DB::table('stock_levels')->where('variant_id', $this->puzzle->id)->update(['on_hand' => -2, 'avg_cost' => '999.0000']);

    $po = Kit::purchase([[$this->puzzle, 5, '120.50']]);
    Kit::receive($po, [$po->items[0]->id => 5]);

    expect(Kit::level($this->puzzle->id)->on_hand)->toBe(3)
        ->and(Kit::avg($this->puzzle->id))->toBe('120.5000')
        ->and(Kit::ledgerBalanced())->toBeTrue();

    // From zero on hand (no level row at all) the average is the landed cost too.
    $po2 = Kit::purchase([[$this->doll, 4, '1000']]);
    Kit::receive($po2, [$po2->items[0]->id => 4], freight: '100');
    expect(Kit::avg($this->doll->id))->toBe('1025.0000');
});

it('averages per location: receiving into a branch leaves the main location untouched', function (): void {
    $branch = StockLocation::query()->create(['code' => 'ctg', 'name' => 'Chattogram', 'name_bn' => 'চট্টগ্রাম', 'is_default' => false, 'is_active' => true]);
    Kit::open($this->puzzle->id, 10);
    $po = Kit::purchase([[$this->puzzle, 4, '400']]);

    $receipt = Kit::receive($po, [$po->items[0]->id => 4], locationId: $branch->id);

    expect($receipt->location_id)->toBe($branch->id)
        ->and(Kit::level($this->puzzle->id, $branch->id)->on_hand)->toBe(4)
        ->and(Kit::avg($this->puzzle->id, $branch->id))->toBe('400.0000')
        ->and(Kit::level($this->puzzle->id, StockLocation::defaultId())->on_hand)->toBe(10)
        ->and(Kit::ledgerBalanced())->toBeTrue();
});

it('never rewrites an existing order item cost snapshot (rule 7)', function (): void {
    $item = OrderItem::factory()->create(['variant_id' => $this->puzzle->id, 'cost_price_snapshot' => '300.0000']);
    $po = Kit::purchase([[$this->puzzle, 10, '500']]);

    Kit::receive($po, [$po->items[0]->id => 10]);

    expect($item->refresh()->cost_price_snapshot)->toBe('300.0000')
        ->and($this->puzzle->refresh()->cost_price->toDecimal())->toBe('500.00');
});

it('quotes exactly what the receipt writes', function (): void {
    Kit::open($this->puzzle->id, 7);
    $po = Kit::purchase([[$this->puzzle, 9, '211.1111', '3.50'], [$this->doll, 2, '1499.99']]);
    [$a, $b] = $po->items->all();

    $quote = app(ReceiptCalculator::class)->quote($po->load('items'), Kit::receipt([$a->id => 4, $b->id => 2], '333.33', '10.01'));
    $receipt = Kit::receive($po, [$a->id => 4, $b->id => 2], '333.33', '10.01');

    $written = $receipt->items->keyBy('purchase_item_id');
    foreach ($quote->lines as $line) {
        expect($written[$line->purchaseItemId]->line_value->toDecimal())->toBe($line->lineValue->toDecimal())
            ->and($written[$line->purchaseItemId]->allocated_cost->toDecimal())->toBe($line->allocatedCost->toDecimal())
            ->and($written[$line->purchaseItemId]->landed_unit_cost)->toBe($line->landedUnitCost)
            ->and($written[$line->purchaseItemId]->avg_cost_after)->toBe($line->avgCostAfter);
    }
    expect($receipt->payable_amount->toDecimal())->toBe($quote->payableAmount()->toDecimal());
});

it('excludes landed costs from the payable when a third party charged them', function (): void {
    $po = Kit::purchase([[$this->puzzle, 2, '100']]);

    $receipt = Kit::receive($po, [$po->items[0]->id => 2], freight: '50', costsToSupplier: false);

    expect($receipt->goods_value->toDecimal())->toBe('200.00')
        ->and($receipt->payable_amount->toDecimal())->toBe('200.00')
        // …but the stock still carries the freight
        ->and($receipt->items[0]->landed_unit_cost)->toBe('125.0000');
});

it('announces the receipt after commit', function (): void {
    Event::fake([PurchaseReceived::class]);
    $po = Kit::purchase([[$this->puzzle, 2, '100']]);

    Kit::receive($po, [$po->items[0]->id => 2]);

    Event::assertDispatched(PurchaseReceived::class, fn (PurchaseReceived $e): bool => $e->purchaseId === $po->id && $e->completed && $e->payableAmount === '200.00');
});

it('cancels a draft or an ordered PO but never one with stock received', function (): void {
    $draft = Kit::purchase([[$this->puzzle, 2, '100']], ordered: false);
    $ordered = Kit::purchase([[$this->puzzle, 2, '100']]);
    $partial = Kit::purchase([[$this->puzzle, 2, '100']]);
    Kit::receive($partial, [$partial->items[0]->id => 1]);

    expect(app(CancelPurchase::class)->handle($draft)->status)->toBe(PurchaseStatus::Cancelled)
        ->and(app(CancelPurchase::class)->handle($ordered)->status)->toBe(PurchaseStatus::Cancelled)
        ->and(fn () => app(CancelPurchase::class)->handle($partial))->toThrow(PurchaseConflict::class)
        ->and(fn () => app(CancelPurchase::class)->handle($draft))->toThrow(PurchaseConflict::class)
        ->and($partial->refresh()->status)->toBe(PurchaseStatus::PartiallyReceived);
});

it('edits only a draft, recomputing the totals', function (): void {
    $draft = Kit::purchase([[$this->puzzle, 2, '100']], ordered: false);
    $data = new PurchaseData($draft->supplier_id, [
        new PurchaseLineData($this->puzzle->id, 3, '100.5', Money::of('1.50')),
        new PurchaseLineData($this->doll->id, 1, '1000', Money::zero()),
    ], CarbonImmutable::parse('2026-11-12'));

    $updated = app(UpdatePurchase::class)->handle($draft, $data);

    expect($updated->items)->toHaveCount(2)
        ->and($updated->subtotal->toDecimal())->toBe('1301.50')
        ->and($updated->discount->toDecimal())->toBe('1.50')
        ->and($updated->total->toDecimal())->toBe('1300.00');

    $ordered = Kit::purchase([[$this->puzzle, 2, '100']]);
    app(UpdatePurchase::class)->handle($ordered, $data);
})->throws(PurchaseConflict::class);
