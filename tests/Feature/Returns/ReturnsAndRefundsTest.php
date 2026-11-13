<?php

declare(strict_types=1);

use App\Domain\Finance\Services\FinanceInvariant;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Enums\ReturnStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderReturn;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Enums\RefundType;
use App\Domain\Payment\Gateways\NullGateway;
use App\Domain\Payment\Models\Refund;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Models\AuditSensitive;
use App\Models\Enums\AuditAction;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Orders\Support as OrderSupport;
use Tests\Feature\Returns\ReturnsKit;

/*
 * Returns & refunds (DATABASE-DESIGN §4.7, PRD O-21): over-return refused, the approval
 * threshold, restocking with the stock invariant, the ledger reversal for a partial
 * refund, an idempotent payout, COGS reversal at the snapshot, and the order statuses
 * after a partial and a full return.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '', 'returns.approval_threshold' => '2000.00']);
    NullGateway::reset();

    $this->a = ReturnsKit::variant(10, '1250.00', '800.00');
    $this->b = ReturnsKit::variant(10, '500.00', '300.00');
    // A ×2 (2500) + B ×1 (500) + 70 delivery = 3070.
    $this->order = ReturnsKit::deliveredOrder([$this->a->id => 2, $this->b->id => 1]);
    $this->lineA = ReturnsKit::line($this->order, $this->a->id);
    $this->lineB = ReturnsKit::line($this->order, $this->b->id);
});

/** @return array<string, mixed> */
function rrLedgerRows(string $sourceType, int $sourceId): array
{
    return DB::table('financial_transactions')->where('source_type', $sourceType)->where('source_id', $sourceId)
        ->orderBy('type')->get(['type', 'direction', 'amount', 'is_cash_movement', 'is_recognised_revenue'])
        ->map(static fn ($r): array => [
            'type' => $r->type, 'direction' => $r->direction, 'amount' => number_format((float) $r->amount, 2, '.', ''),
            'cash' => (bool) $r->is_cash_movement, 'recognised' => (bool) $r->is_recognised_revenue,
        ])->all();
}

function rrAssertInvariants(): void
{
    OrderSupport::assertStockInvariants();
    expect(app(FinanceInvariant::class)->drift())->toBe([]);
}

function rrOpenReturn(Order $order, array $items, array $extra = []): OrderReturn
{
    test()->postJson("/api/v1/admin/orders/{$order->id}/returns", ['items' => $items, 'reason' => 'defective', ...$extra])->assertCreated();

    return OrderReturn::query()->latest('id')->firstOrFail();
}

function rrInspect(OrderReturn $return, array $items): ?Refund
{
    test()->postJson("/api/v1/admin/returns/{$return->id}/inspect", ['items' => $items])->assertOk();

    return $return->refresh()->refund;
}

function rrPay(Refund $refund, array $body = ['method' => 'bkash', 'reference' => 'BK8XYZ123'], ?string $key = null)
{
    return test()->postJson("/api/v1/admin/refunds/{$refund->id}/pay", $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

it('refuses a return of more than was delivered less what already came back', function (): void {
    ReturnsKit::actingAs(Role::OrderOperator);

    $this->postJson("/api/v1/admin/orders/{$this->order->id}/returns", [
        'items' => [['order_item_id' => $this->lineA->id, 'quantity' => 3]], 'reason' => 'defective',
    ])->assertUnprocessable()->assertJsonPath('errors.0.field', 'items.0.quantity');

    // One unit is held by an open return, so only one more can be requested.
    rrOpenReturn($this->order, [['order_item_id' => $this->lineA->id, 'quantity' => 1]]);
    $this->postJson("/api/v1/admin/orders/{$this->order->id}/returns", [
        'items' => [['order_item_id' => $this->lineA->id, 'quantity' => 2]], 'reason' => 'defective',
    ])->assertUnprocessable();

    // A line from another order is not this order's.
    $this->postJson("/api/v1/admin/orders/{$this->order->id}/returns", [
        'items' => [['order_item_id' => 999999, 'quantity' => 1]], 'reason' => 'defective',
    ])->assertUnprocessable()->assertJsonPath('errors.0.field', 'items.0.order_item_id');

    expect(OrderReturn::query()->count())->toBe(1);
});

it('refuses a return on an order that was never delivered', function (): void {
    ReturnsKit::actingAs(Role::OrderOperator);
    $pending = Tests\Feature\Ordering\Support\OrderFixtures::place([$this->b->id => 1]);

    $this->postJson("/api/v1/admin/orders/{$pending->id}/returns", [
        'items' => [['order_item_id' => $pending->items()->value('id'), 'quantity' => 1]], 'reason' => 'changed_mind',
    ])->assertConflict()->assertJsonPath('type', 'https://toymartbd.com/problems/conflict');
});

it('restocks resellable units, writes damaged ones off, and keeps both invariants', function (): void {
    ReturnsKit::actingAs(Role::OrderOperator);
    $return = rrOpenReturn($this->order, [['order_item_id' => $this->lineA->id, 'quantity' => 2]]);

    rrInspect($return, [['order_item_id' => $this->lineA->id, 'resellable' => 1, 'damaged' => 1]]);

    $movements = StockMovement::query()->where('reference_type', 'order_return')->where('reference_id', $return->id)->orderBy('id')->get();
    expect($movements->map(fn ($m) => [$m->type->value, $m->quantity])->all())->toBe([['customer_return_in', 2], ['damage_out', -1]])
        ->and((string) $movements[0]->unit_cost)->toStartWith('800.00');
    // 10 opening − 2 sold + 2 back − 1 damaged.
    expect(StockLevel::query()->where('variant_id', $this->a->id)->value('on_hand'))->toBe(9);

    // COGS reversed for both units at the snapshot; the damaged one written off.
    expect(rrLedgerRows('order_return', $return->id))->toBe([
        ['type' => 'cogs', 'direction' => 'in', 'amount' => '1600.00', 'cash' => false, 'recognised' => true],
        ['type' => 'inventory_write_off', 'direction' => 'out', 'amount' => '800.00', 'cash' => false, 'recognised' => true],
    ]);
    rrAssertInvariants();
});

it('books a partial refund: recognised refund, COGS reversal, cash out on payout — and partially_returned / partially_refunded', function (): void {
    ReturnsKit::actingAs(Role::OrderOperator);
    $return = rrOpenReturn($this->order, [['order_item_id' => $this->lineA->id, 'quantity' => 1]]);
    $refund = rrInspect($return, [['order_item_id' => $this->lineA->id, 'resellable' => 1, 'damaged' => 0]]);

    expect($refund->amount->toDecimal())->toBe('1250.00')
        ->and($refund->status)->toBe(RefundStatus::Requested)
        ->and($refund->type)->toBe(RefundType::Partial)
        ->and($refund->items->sole()->quantity)->toBe(1);

    $order = $this->order->refresh();
    expect($order->status)->toBe(OrderStatus::PartiallyReturned)
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::Fulfilled)
        ->and($this->lineA->refresh()->quantity_returned)->toBe(1)
        ->and(StockLevel::query()->where('variant_id', $this->a->id)->value('on_hand'))->toBe(9);

    // ৳1,250 ≤ the ৳2,000 threshold: the operator approves it.
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()->assertJsonPath('status', 'approved');
    expect(rrLedgerRows('refund', $refund->id))->toBe([
        ['type' => 'refund', 'direction' => 'out', 'amount' => '1250.00', 'cash' => false, 'recognised' => true],
    ])->and(AuditSensitive::query()->where('action', AuditAction::RefundApproved->value)->count())->toBe(1);

    rrPay($refund)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('method', 'bkash')->assertJsonPath('gateway_reference', 'BK8XYZ123');
    expect(rrLedgerRows('refund_payout', $refund->id))->toBe([
        ['type' => 'refund', 'direction' => 'out', 'amount' => '1250.00', 'cash' => true, 'recognised' => false],
    ])->and(rrLedgerRows('order_return', $return->id))->toBe([
        ['type' => 'cogs', 'direction' => 'in', 'amount' => '800.00', 'cash' => false, 'recognised' => true],
    ]);

    $order->refresh();
    expect($order->payment_status)->toBe(OrderPaymentStatus::PartiallyRefunded)
        ->and($order->refunded_total->toDecimal())->toBe('1250.00')
        ->and($return->refresh()->status)->toBe(ReturnStatus::Closed);

    // Order P&L: 3000 goods + 70 delivery − 1900 COGS + 800 reversed − 1250 refunded = 720.
    expect(DB::table('order_financials')->where('order_id', $order->id)->value('net_profit'))->toEqual('720.00');
    rrAssertInvariants();
});

it('returns everything: returned / fulfilment returned / refunded, with the delivery charge, as a full refund', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    $return = rrOpenReturn($this->order, [
        ['order_item_id' => $this->lineA->id, 'quantity' => 2],
        ['order_item_id' => $this->lineB->id, 'quantity' => 1],
    ], ['refund_shipping' => true]);
    $refund = rrInspect($return, [
        ['order_item_id' => $this->lineA->id, 'resellable' => 2, 'damaged' => 0],
        ['order_item_id' => $this->lineB->id, 'resellable' => 1, 'damaged' => 0],
    ]);

    expect($refund->amount->toDecimal())->toBe('3070.00')
        ->and($refund->shipping_amount->toDecimal())->toBe('70.00')
        ->and($refund->type)->toBe(RefundType::Full);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();
    rrPay($refund, ['method' => 'cash'])->assertOk()->assertJsonPath('status', 'completed');

    $order = $this->order->refresh();
    expect($order->status)->toBe(OrderStatus::Returned)
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::Returned)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Refunded)
        ->and($order->refunded_total->toDecimal())->toBe('3070.00');
    rrAssertInvariants();
});

it('moves a partially returned order to returned when the rest comes back', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    $first = rrOpenReturn($this->order, [['order_item_id' => $this->lineB->id, 'quantity' => 1]]);
    rrInspect($first, [['order_item_id' => $this->lineB->id, 'resellable' => 1, 'damaged' => 0]]);
    expect($this->order->refresh()->status)->toBe(OrderStatus::PartiallyReturned);

    $second = rrOpenReturn($this->order, [['order_item_id' => $this->lineA->id, 'quantity' => 2]]);
    rrInspect($second, [['order_item_id' => $this->lineA->id, 'resellable' => 2, 'damaged' => 0]]);
    expect($this->order->refresh()->status)->toBe(OrderStatus::Returned);

    expect(Refund::query()->where('order_id', $this->order->id)->get()->sum(fn (Refund $r) => (float) $r->amount->toDecimal()))->toBe(3000.0);
    rrAssertInvariants();
});

it('needs refunds.approve above the threshold', function (): void {
    $operator = ReturnsKit::actingAs(Role::OrderOperator);
    $return = rrOpenReturn($this->order, [['order_item_id' => $this->lineA->id, 'quantity' => 2]]);
    $refund = rrInspect($return, [['order_item_id' => $this->lineA->id, 'resellable' => 2, 'damaged' => 0]]);
    expect($refund->amount->toDecimal())->toBe('2500.00');

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertForbidden()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/forbidden');
    $this->getJson("/api/v1/admin/orders/{$this->order->id}/returns")->assertOk()
        ->assertJsonPath('refunds.0.needs_manager', true)
        ->assertJsonPath('refunds.0.can.approve', false);

    // The Action refuses too, whoever calls it.
    expect(fn () => app(App\Domain\Ordering\Actions\Returns\ApproveRefund::class)->handle($refund, $operator->id, false))
        ->toThrow(App\Domain\Ordering\Exceptions\ReturnNotAllowed::class);

    ReturnsKit::actingAs(Role::Accountant);
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()->assertJsonPath('status', 'approved');
    expect($refund->refresh()->approved_by)->not->toBe($operator->id);
});

it('treats a second payout as a no-op', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    $return = rrOpenReturn($this->order, [['order_item_id' => $this->lineB->id, 'quantity' => 1]]);
    $refund = rrInspect($return, [['order_item_id' => $this->lineB->id, 'resellable' => 1, 'damaged' => 0]]);
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();

    rrPay($refund)->assertOk()->assertJsonPath('status', 'completed');
    // A fresh key (not a replay): the Action itself sees `completed` and changes nothing.
    rrPay($refund, ['method' => 'nagad', 'reference' => 'NG-OTHER'])->assertOk()->assertJsonPath('method', 'bkash');
    // The same key replays the first response.
    $key = (string) Str::uuid();
    rrPay($refund, key: $key)->assertOk();
    rrPay($refund, key: $key)->assertOk();

    expect(DB::table('financial_transactions')->where('source_type', 'refund_payout')->count())->toBe(1)
        ->and(DB::table('order_events')->where('order_id', $this->order->id)->where('type', 'refund_paid')->count())->toBe(1)
        ->and($this->order->refresh()->refunded_total->toDecimal())->toBe('500.00');
    rrAssertInvariants();
});

it('requires the payout method and reference for a hand-paid refund, and an Idempotency-Key', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    $return = rrOpenReturn($this->order, [['order_item_id' => $this->lineB->id, 'quantity' => 1]]);
    $refund = rrInspect($return, [['order_item_id' => $this->lineB->id, 'resellable' => 1, 'damaged' => 0]]);

    rrPay($refund)->assertConflict(); // not approved yet
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/pay", ['method' => 'bkash', 'reference' => 'X'])->assertStatus(400);
    rrPay($refund, [])->assertUnprocessable()->assertJsonPath('errors.0.field', 'method');
    rrPay($refund, ['method' => 'bkash'])->assertUnprocessable()->assertJsonPath('errors.0.field', 'reference');
    expect($refund->refresh()->status)->toBe(RefundStatus::Approved);
});

it('reverses COGS at the snapshot cost, not today\'s cost', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    $this->a->forceFill(['cost_price' => '999.00'])->save();

    $return = rrOpenReturn($this->order, [['order_item_id' => $this->lineA->id, 'quantity' => 1]]);
    rrInspect($return, [['order_item_id' => $this->lineA->id, 'resellable' => 1, 'damaged' => 0]]);

    expect(rrLedgerRows('order_return', $return->id))->toBe([
        ['type' => 'cogs', 'direction' => 'in', 'amount' => '800.00', 'cash' => false, 'recognised' => true],
    ])->and((string) StockMovement::query()->where('reference_type', 'order_return')->value('unit_cost'))->toStartWith('800.00');
});

it('refunds an online payment through its gateway, never more than was captured', function (): void {
    app(PaymentGatewayRegistry::class)->register(PaymentMethod::Bkash, NullGateway::class);
    $order = ReturnsKit::deliveredOrder([$this->b->id => 2], PaymentMethod::Bkash);
    $line = ReturnsKit::line($order, $this->b->id);
    ReturnsKit::actingAs(Role::Manager);

    $return = rrOpenReturn($order, [['order_item_id' => $line->id, 'quantity' => 1]]);
    $refund = rrInspect($return, [['order_item_id' => $line->id, 'resellable' => 1, 'damaged' => 0]]);
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk()->assertJsonPath('payout', 'gateway');

    // A failed gateway answer leaves the refund failed; a retry completes it.
    NullGateway::$nextRefund = new RefundResult(RefundStatus::Failed, payload: ['fixture' => 'refund.failed']);
    rrPay($refund, [])->assertOk()->assertJsonPath('status', 'failed');
    NullGateway::$nextRefund = null;
    rrPay($refund, [])->assertOk()->assertJsonPath('status', 'completed');

    expect(NullGateway::$refundCalls)->toHaveCount(2)
        ->and(NullGateway::$refundCalls[1]['amount'])->toBe('500.00')
        ->and($order->refresh()->payment_status)->toBe(OrderPaymentStatus::PartiallyRefunded);

    // A refund above what is left of the capture is refused before any gateway call.
    $other = Refund::query()->create([
        'order_id' => $order->id, 'payment_id' => $refund->payment_id, 'amount' => '1000.00', 'reason' => 'test',
        'type' => 'partial', 'method' => 'bkash', 'status' => RefundStatus::Approved,
    ]);
    rrPay($other, [])->assertConflict();
    expect(NullGateway::$refundCalls)->toHaveCount(2);
});

it('rejects a requested refund, freeing its units and closing the return', function (): void {
    ReturnsKit::actingAs(Role::Manager);
    $return = rrOpenReturn($this->order, [['order_item_id' => $this->lineB->id, 'quantity' => 1]]);
    $refund = rrInspect($return, [['order_item_id' => $this->lineB->id, 'resellable' => 1, 'damaged' => 0]]);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/reject", ['note' => 'Opened and used'])->assertOk()->assertJsonPath('status', 'rejected');
    expect($return->refresh()->status)->toBe(ReturnStatus::Closed)
        ->and($this->lineB->refresh()->quantity_refunded)->toBe(0)
        ->and(DB::table('financial_transactions')->where('source_type', 'refund')->count())->toBe(0);
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertConflict();
});

it('lists returns with per-status counts and an allowlisted filter', function (): void {
    ReturnsKit::actingAs(Role::Viewer);
    $return = OrderReturn::query()->create([
        'number' => 'RMA-26-00001', 'order_id' => $this->order->id, 'status' => ReturnStatus::Requested, 'reason' => 'defective',
    ]);
    $return->items()->create(['order_item_id' => $this->lineA->id, 'quantity' => 1]);

    $this->getJson('/api/v1/admin/returns')->assertOk()
        ->assertJsonPath('data.0.number', 'RMA-26-00001')
        ->assertJsonPath('data.0.value', '1250.00')
        ->assertJsonPath('data.0.order.customer_name', $this->order->shipping_name)
        ->assertJsonPath('data.0.refund', null)
        ->assertJsonPath('meta.counts.requested', 1);
    $this->getJson('/api/v1/admin/returns?filter[status]=bogus')->assertStatus(400);
    $this->getJson('/api/v1/admin/returns?filter[phone]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/returns/'.$return->id)->assertOk()->assertJsonMissingPath('order.shipping_phone');
});
