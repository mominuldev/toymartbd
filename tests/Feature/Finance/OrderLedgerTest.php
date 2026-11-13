<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\RecognizeOrderRevenue;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Finance\Services\Receivables;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderFinancial;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Finance\FinanceKit;

/** @return array<string, array{direction: string, amount: string, cash: bool, recognised: bool}> */
function ledgerFor(int $orderId): array
{
    return FinancialTransaction::query()->where('source_type', 'order')->where('source_id', $orderId)->get()
        ->mapWithKeys(fn (FinancialTransaction $t): array => [$t->type->value => [
            'direction' => $t->direction->value,
            'amount' => $t->amount->toDecimal(),
            'cash' => $t->is_cash_movement,
            'recognised' => $t->is_recognised_revenue,
        ]])->all();
}

it('writes recognised revenue, discount, shipping and snapshot COGS on delivery — none of it cash', function (): void {
    $order = FinanceKit::shippedOrder();
    FinanceKit::deliver($order);

    expect(ledgerFor($order->id))->toEqual([
        'sale_revenue' => ['direction' => 'in', 'amount' => '1200.00', 'cash' => false, 'recognised' => true],
        'cogs' => ['direction' => 'out', 'amount' => '700.00', 'cash' => false, 'recognised' => true],
        'discount_given' => ['direction' => 'out', 'amount' => '100.00', 'cash' => false, 'recognised' => true],
        'shipping_revenue' => ['direction' => 'in', 'amount' => '70.00', 'cash' => false, 'recognised' => true],
    ]);

    $f = OrderFinancial::query()->findOrFail($order->id);
    expect($f->revenue->toDecimal())->toBe('1100.00')          // Σ line_total (§5.4)
        ->and($f->cogs->toDecimal())->toBe('700.00')
        ->and($f->gross_profit->toDecimal())->toBe('400.00')
        ->and($f->discount_cost->toDecimal())->toBe('100.00')
        ->and($f->net_profit->toDecimal())->toBe('470.00')      // + shipping revenue
        ->and($f->margin_percent)->toBe('42.73')
        ->and(app(Receivables::class)->forOrder($order->id)->toDecimal())->toBe('1170.00'); // = grand_total
    FinanceKit::assertFinanceInvariant();
});

it('uses the cost snapshot, not the variant\'s current cost (rule 7)', function (): void {
    $order = FinanceKit::shippedOrder();
    $order->items->first()->variant->forceFill(['cost_price' => '999.00'])->save();
    FinanceKit::deliver($order);

    expect(ledgerFor($order->id)['cogs']['amount'])->toBe('700.00');
});

it('is a no-op when OrderDelivered is replayed', function (): void {
    $order = FinanceKit::shippedOrder();
    FinanceKit::deliver($order);
    $before = FinancialTransaction::query()->count();

    event(new OrderDelivered($order->id, $order->customer_id, 'shipped', 'delivered'));
    app(RecognizeOrderRevenue::class)->handle($order->id);

    expect(FinancialTransaction::query()->count())->toBe($before)->and($before)->toBe(4);
    FinanceKit::assertFinanceInvariant();
});

it('recognises nothing for an order cancelled before delivery', function (): void {
    $order = Order::factory()->status(OrderStatus::Confirmed)->create();
    app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest, null, null);
    app(RecognizeOrderRevenue::class)->handle($order->id);

    expect(FinancialTransaction::query()->count())->toBe(0)
        ->and(OrderFinancial::query()->count())->toBe(0);
});

it('dates revenue on the Dhaka day of delivery: 23:30 UTC belongs to the next day', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-20 23:30:00', 'UTC'));
    $order = FinanceKit::shippedOrder();
    FinanceKit::deliver($order);

    expect(FinancialTransaction::query()->pluck('occurred_on')->map->toDateString()->unique()->values()->all())->toBe(['2026-11-21']);

    Artisan::call('toymart:finance:rollup', ['--from' => '2026-11-20', '--to' => '2026-11-21']);
    $days = DB::table('daily_financial_summaries')->orderBy('summary_date')->get()->keyBy(fn ($r) => substr((string) $r->summary_date, 0, 10));
    expect((string) $days['2026-11-20']->sale_revenue)->toEqual('0')
        ->and(number_format((float) $days['2026-11-21']->sale_revenue, 2, '.', ''))->toBe('1200.00')
        ->and((int) $days['2026-11-21']->orders_delivered)->toBe(1)
        ->and((int) $days['2026-11-20']->orders_delivered)->toBe(0)
        ->and((int) $days['2026-11-21']->units_sold)->toBe(2);
});

it('writes cash, not revenue, when COD is received — and receivables fall by it', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $order = FinanceKit::shippedOrder();
    FinanceKit::deliver($order);
    $profitBefore = OrderFinancial::query()->findOrFail($order->id)->net_profit->toDecimal();

    $this->postJson("/api/v1/admin/finance/orders/{$order->id}/cod-receipts", [
        'amount' => '1000.00', 'received_on' => now('Asia/Dhaka')->toDateString(), 'reference' => 'STF-PAY-0091',
    ], ['Idempotency-Key' => 'cod-key-0001'])->assertCreated()->assertJsonPath('order_outstanding', '170.00');

    $received = FinancialTransaction::query()->where('type', 'cod_received')->sole();
    expect($received->is_cash_movement)->toBeTrue()
        ->and($received->is_recognised_revenue)->toBeFalse()
        ->and($received->amount->toDecimal())->toBe('1000.00')
        ->and(OrderFinancial::query()->findOrFail($order->id)->net_profit->toDecimal())->toBe($profitBefore)
        ->and(app(Receivables::class)->total()->toDecimal())->toBe('170.00');

    // More than is outstanding is refused; so is a COD receipt for an undelivered order.
    $this->postJson("/api/v1/admin/finance/orders/{$order->id}/cod-receipts", ['amount' => '170.01', 'received_on' => now('Asia/Dhaka')->toDateString()], ['Idempotency-Key' => 'cod-key-0002'])
        ->assertStatus(409);
    $pending = Order::factory()->create();
    $this->postJson("/api/v1/admin/finance/orders/{$pending->id}/cod-receipts", ['amount' => '10.00', 'received_on' => now('Asia/Dhaka')->toDateString()], ['Idempotency-Key' => 'cod-key-0003'])
        ->assertStatus(409);

    $this->getJson('/api/v1/admin/finance/receivables')->assertOk()
        ->assertJsonPath('recognised', '1170.00')
        ->assertJsonPath('received', '1000.00')
        ->assertJsonPath('outstanding', '170.00')
        ->assertJsonPath('open_orders', 1)
        ->assertJsonPath('aging.0_7', '170.00');
    FinanceKit::assertFinanceInvariant();
});

it('replays a COD receipt with the same Idempotency-Key instead of recording it twice', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    $body = ['amount' => '500.00', 'received_on' => now('Asia/Dhaka')->toDateString()];

    $this->postJson("/api/v1/admin/finance/orders/{$order->id}/cod-receipts", $body, ['Idempotency-Key' => 'same-key-0001'])->assertCreated();
    $this->postJson("/api/v1/admin/finance/orders/{$order->id}/cod-receipts", $body, ['Idempotency-Key' => 'same-key-0001']);

    expect(FinancialTransaction::query()->where('type', 'cod_received')->count())->toBe(1);
});

it('refuses a COD receipt for a prepaid order', function (): void {
    FinanceKit::actingAs(Role::Accountant);
    $order = FinanceKit::deliver(FinanceKit::shippedOrder('bkash'));

    $this->postJson("/api/v1/admin/finance/orders/{$order->id}/cod-receipts", ['amount' => '10.00', 'received_on' => now('Asia/Dhaka')->toDateString()], ['Idempotency-Key' => 'prepaid-key-01'])
        ->assertStatus(409);
    expect(app(Receivables::class)->total()->toDecimal())->toBe('0.00');
});

it('detects drift between order_financials and the ledger, and --rebuild repairs it', function (): void {
    $order = FinanceKit::deliver(FinanceKit::shippedOrder());
    FinanceKit::assertFinanceInvariant();
    expect(Artisan::call('toymart:finance:verify'))->toBe(0);

    DB::table('order_financials')->where('order_id', $order->id)->update(['net_profit' => '1.00']);

    expect(Artisan::call('toymart:finance:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('net_profit');
    expect(Artisan::call('toymart:finance:verify', ['--rebuild' => true]))->toBe(0);
    FinanceKit::assertFinanceInvariant();
});
