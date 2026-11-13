<?php

declare(strict_types=1);

use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Inventory\Actions\ApproveStockAdjustment;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Enums\StockAdjustmentStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Inventory\InventoryOpsKit as Kit;

/*
 * P5-INV stock adjustments with approval: above the (PLACEHOLDER) threshold an
 * adjustment waits `pending` and writes nothing; only approval writes movements; a
 * rejection never does. Damage/loss/theft/expiry book an inventory_write_off at the
 * weighted-average cost, recognised in the P&L and not cash.
 */

const ADJ_API = '/api/v1/admin/inventory/adjustments';

function approvalPost(string $uri, array $body = []): Illuminate\Testing\TestResponse
{
    return test()->postJson($uri, $body, ['Idempotency-Key' => (string) Str::uuid()]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'services.storefront.url' => '', 'services.storefront.revalidate_secret' => '',
        'inventory.adjustment_approval.max_units' => 10,
        'inventory.adjustment_approval.max_value' => '5000.00',
    ]);

    $this->staff = Kit::user(Role::InventoryStaff, actAs: false);
    $this->manager = Kit::user(Role::Manager, actAs: false);
    $this->puzzle = Kit::variant('Wooden Alphabet Puzzle', '310.00');
    Kit::stock($this->puzzle->id, 40);
});

it('applies an adjustment at or below the threshold at once', function (): void {
    Kit::actAs($this->staff);

    approvalPost(ADJ_API, ['reason' => 'damage', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -2]]])
        ->assertCreated()
        ->assertJsonPath('status', 'applied')
        ->assertJsonPath('moved_stock', true)
        ->assertJsonPath('requested_units', 2)
        ->assertJsonPath('requested_value', '620.00');

    expect(Kit::onHand($this->puzzle->id))->toBe(38);
    Kit::assertInvariants();
});

it('holds an adjustment above the unit threshold as pending and moves nothing', function (): void {
    Kit::actAs($this->staff);

    approvalPost(ADJ_API, ['reason' => 'theft', 'note' => 'Shelf B count after Eid', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -11]]])
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('moved_stock', false)
        ->assertJsonPath('items.0.requested_change', -11);

    expect(Kit::onHand($this->puzzle->id))->toBe(40)
        ->and(StockMovement::query()->where('reference_type', StockAdjustment::REFERENCE_TYPE)->count())->toBe(0)
        ->and(FinancialTransaction::query()->count())->toBe(0);
    Kit::assertInvariants();
});

it('holds an adjustment above the value threshold even with few units', function (): void {
    Kit::actAs($this->staff);
    $bike = Kit::variant('Kids Tricycle', '3250.00', 'বাচ্চাদের ট্রাইসাইকেল');
    Kit::stock($bike->id, 5);

    approvalPost(ADJ_API, ['reason' => 'damage', 'lines' => [['variant_id' => $bike->id, 'quantity' => -2]]])
        ->assertCreated()->assertJsonPath('status', 'pending');

    expect(Kit::onHand($bike->id))->toBe(5);
});

it('lets an approver apply a large adjustment directly, recorded as approved by them', function (): void {
    Kit::actAs($this->manager);

    approvalPost(ADJ_API, ['reason' => 'found', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => 15]]])
        ->assertCreated()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('approved_by', $this->manager->name);

    expect(Kit::onHand($this->puzzle->id))->toBe(55);
    Kit::assertInvariants();
});

it('writes the movements only on approval, against the stock as it is then', function (): void {
    Kit::actAs($this->staff);
    $id = approvalPost(ADJ_API, ['reason' => 'recount', 'lines' => [['variant_id' => $this->puzzle->id, 'counted' => 25]]])
        ->assertCreated()->assertJsonPath('status', 'pending')->json('id');

    // An order reserves a unit between the count and the approval; the count is still the truth.
    app(ReserveStock::class)->handle([$this->puzzle->id => 1]);

    Kit::actAs($this->manager);
    approvalPost(ADJ_API."/{$id}/approve", ['note' => 'Matches the shelf'])
        ->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('approved_by', $this->manager->name)
        ->assertJsonPath('decision_note', 'Matches the shelf')
        ->assertJsonPath('items.0.quantity_before', 40)
        ->assertJsonPath('items.0.quantity_after', 25)
        ->assertJsonPath('items.0.change', -15);

    expect(Kit::onHand($this->puzzle->id))->toBe(25)
        ->and(StockMovement::query()->where('reference_type', StockAdjustment::REFERENCE_TYPE)->where('reference_id', $id)->sum('quantity'))->toBe(-15);
    Kit::assertInvariants();
});

it('refuses a second decision with 409 and never moves stock twice', function (): void {
    Kit::actAs($this->staff);
    $id = approvalPost(ADJ_API, ['reason' => 'loss', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -12]]])->json('id');

    Kit::actAs($this->manager);
    approvalPost(ADJ_API."/{$id}/approve")->assertOk();
    approvalPost(ADJ_API."/{$id}/approve")->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
    approvalPost(ADJ_API."/{$id}/reject", ['note' => 'late'])->assertStatus(409);

    expect(Kit::onHand($this->puzzle->id))->toBe(28);
    Kit::assertInvariants();
});

it('writes nothing for a rejected adjustment, and requires a reason to reject', function (): void {
    Kit::actAs($this->staff);
    $id = approvalPost(ADJ_API, ['reason' => 'expiry', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -30]]])->json('id');

    Kit::actAs($this->manager);
    $this->postJson(ADJ_API."/{$id}/reject", [])->assertUnprocessable()->assertJsonPath('errors.0.field', 'note');
    $this->postJson(ADJ_API."/{$id}/reject", ['note' => 'Batch is not expired — wrong lot'])
        ->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('moved_stock', false)
        ->assertJsonPath('rejected_by', $this->manager->name);

    expect(Kit::onHand($this->puzzle->id))->toBe(40)
        ->and(StockMovement::query()->where('reference_type', StockAdjustment::REFERENCE_TYPE)->count())->toBe(0)
        ->and(FinancialTransaction::query()->count())->toBe(0)
        ->and(StockAdjustment::query()->sole()->status)->toBe(StockAdjustmentStatus::Rejected);
    Kit::assertInvariants();
});

it('refuses self-approval', function (): void {
    Kit::actAs($this->staff);
    $id = approvalPost(ADJ_API, ['reason' => 'loss', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -12]]])->json('id');

    expect(fn () => app(ApproveStockAdjustment::class)->handle($id, $this->staff->id))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(StockAdjustment::query()->find($id)->status)->toBe(StockAdjustmentStatus::Pending);
});

it('keeps a pending adjustment pending when a line can no longer apply', function (): void {
    Kit::actAs($this->staff);
    $id = approvalPost(ADJ_API, ['reason' => 'damage', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -30]]])->json('id');
    app(ReserveStock::class)->handle([$this->puzzle->id => 15]); // 40 on hand, 15 now held for orders

    Kit::actAs($this->manager);
    approvalPost(ADJ_API."/{$id}/approve")->assertUnprocessable()->assertJsonPath('errors.0.field', 'items.0');

    expect(StockAdjustment::query()->find($id)->status)->toBe(StockAdjustmentStatus::Pending)
        ->and(Kit::onHand($this->puzzle->id))->toBe(40);
    Kit::assertInvariants();
});

it('books damage as an inventory write-off at weighted-average cost: P&L, not cash, Dhaka date', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-20 19:30:00', 'UTC')); // 01:30 on the 21st in Dhaka
    Kit::actAs($this->staff);
    $id = approvalPost(ADJ_API, ['reason' => 'damage', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -3]]])->json('id');

    $entry = FinancialTransaction::query()->sole();
    expect($entry->type->value)->toBe('inventory_write_off')
        ->and($entry->direction->value)->toBe('out')
        ->and((string) $entry->getRawOriginal('amount'))->toBe('930')
        ->and($entry->source_type)->toBe('stock_adjustment')
        ->and($entry->source_id)->toBe($id)
        ->and($entry->is_cash_movement)->toBeFalse()
        ->and($entry->is_recognised_revenue)->toBeTrue()
        ->and(Carbon\CarbonImmutable::parse($entry->getRawOriginal('occurred_on'))->toDateString())->toBe('2026-11-21');
    Kit::assertInvariants();
});

it('books the write-off only when a pending loss is approved', function (): void {
    Kit::actAs($this->staff);
    $id = approvalPost(ADJ_API, ['reason' => 'theft', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -20]]])->json('id');
    expect(FinancialTransaction::query()->count())->toBe(0);

    Kit::actAs($this->manager);
    approvalPost(ADJ_API."/{$id}/approve")->assertOk();

    expect(FinancialTransaction::query()->where('type', 'inventory_write_off')->where('source_id', $id)->count())->toBe(1)
        ->and((string) FinancialTransaction::query()->sole()->getRawOriginal('amount'))->toBe('6200');
});

it('books no write-off for found stock, a recount gain or a gift/sample', function (string $reason, int $quantity): void {
    Kit::actAs($this->staff);
    approvalPost(ADJ_API, ['reason' => $reason, 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => $quantity]]])->assertCreated();

    expect(FinancialTransaction::query()->count())->toBe(0);
})->with([['found', 2], ['recount', 1], ['gift_sample', -1]]);

it('lists the approval queue with the pending count', function (): void {
    Kit::actAs($this->staff);
    approvalPost(ADJ_API, ['reason' => 'damage', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -1]]]);
    approvalPost(ADJ_API, ['reason' => 'theft', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -12]]]);

    $this->getJson(ADJ_API.'?filter[status]=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reason', 'theft')
        ->assertJsonPath('data.0.items.0.product_name_bn', 'কাঠের বর্ণমালা পাজল')
        ->assertJsonPath('meta.counts.pending', 1);
    $this->getJson(ADJ_API.'?filter[status]=bogus')->assertStatus(400);
    $this->getJson(ADJ_API.'?filter[nope]=1')->assertStatus(400);
});

it('filters the movement ledger by adjustment reason', function (): void {
    Kit::actAs($this->staff);
    approvalPost(ADJ_API, ['reason' => 'damage', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -1]]]);
    approvalPost(ADJ_API, ['reason' => 'theft', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -2]]]);

    $this->getJson('/api/v1/admin/inventory/movements?filter[reason]=theft')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.adjustment_reason', 'theft')
        ->assertJsonPath('data.0.quantity', -2);
    $this->getJson('/api/v1/admin/inventory/movements?filter[reason]=fire')->assertStatus(400);
});
