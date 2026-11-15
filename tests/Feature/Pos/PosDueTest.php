<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pos\Models\PosDueCollection;
use App\Domain\Pos\Models\PosDueCollectionAllocation;
use App\Domain\Pos\Services\PosDueSettings;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Pos\PosKit;

/*
 * POS Due / baki (owner decision 2026-11-15): due is the unpaid part of the order — no payment
 * row, no cash; the order is still delivered at the counter (revenue recognised) with
 * due_total > 0. Guards: pos.sell_on_due, a known customer (phone + name), the due limit.
 * Collected later at the counter, FIFO over the customer's oldest POS orders, as real payments
 * booked to the ledger like sale tenders. Both invariants after every path.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->branch = PosKit::branch();
    $this->manager = PosKit::cashier(Role::Manager);
    $this->session = PosKit::open($this->branch, $this->manager);
    $this->toy = PosKit::variant($this->branch, 20, '1850.00', '1100.00');
    $this->small = PosKit::variant($this->branch, 20, '600.00', '300.00');
});

function dueInvariants(): void
{
    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0]);
}

/** @param  array<string, mixed>  $extra */
function dueSale(mixed $session, int $variantId, string $total, string $due, array $extra = []): TestResponse
{
    $cash = bcsub($total, $due, 2);
    $tenders = [];
    if (bccomp($cash, '0', 2) > 0) {
        $tenders[] = ['method' => 'cash', 'amount' => $cash];
    }
    $tenders[] = ['method' => 'due', 'amount' => $due];

    return PosKit::charge($session, [$variantId => 1], $tenders, $total, ['customer_phone' => PosKit::PHONE, 'customer_name' => 'Rahima Akter', ...$extra]);
}

/** @param  list<array{method: string, amount: string, reference?: string}>  $tenders */
function collectDue(mixed $session, array $tenders, ?string $key = null, string $phone = PosKit::PHONE): TestResponse
{
    return test()->withHeader('Idempotency-Key', $key ?? 'due-'.bin2hex(random_bytes(8)))
        ->postJson("/api/v1/admin/pos/sessions/{$session->id}/due-collections", ['customer_phone' => $phone, 'tenders' => $tenders]);
}

it('sells part on due: delivered, due_total kept, partially paid, no payment for the due, revenue but no cash for it', function (): void {
    $response = dueSale($this->session, $this->toy->id, '1850.00', '1000.00')
        ->assertCreated()
        ->assertJsonPath('due_amount', '1000.00')
        ->assertJsonPath('tendered_total', '850.00')
        ->assertJsonPath('change_due', '0.00');

    $order = Order::query()->with('payments')->findOrFail($response->json('order_id'));
    expect($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::PartiallyPaid)
        ->and((string) $order->paid_total)->toBe('850.00')
        ->and((string) $order->due_total)->toBe('1000.00')
        ->and($order->payments)->toHaveCount(1)
        ->and($order->payments[0]->method->value)->toBe('cash')
        ->and(Payment::query()->where('method', 'due')->exists())->toBeFalse();

    // Revenue: the whole sale, on delivery. Cash: only the 850 that moved (rule 10).
    $revenue = FinancialTransaction::query()->where('source_type', 'order')->where('source_id', $order->id)->where('type', 'sale_revenue')->sole();
    expect((string) $revenue->amount)->toBe('1850.00');
    $cash = FinancialTransaction::query()->where('type', 'pos_receipt')->get();
    expect($cash)->toHaveCount(1)->and((string) $cash[0]->amount)->toBe('850.00')
        ->and(FinancialTransaction::query()->where('type', 'cod_received')->exists())->toBeFalse();
    dueInvariants();
});

it('sells all on due: unpaid, no payment rows at all, receipt shows the due', function (): void {
    $response = dueSale($this->session, $this->small->id, '600.00', '600.00', ['customer_name' => 'রহিমা আক্তার'])->assertCreated();
    $order = Order::query()->findOrFail($response->json('order_id'));
    expect($order->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and((string) $order->due_total)->toBe('600.00')
        ->and($order->payment_method)->toBeNull()
        ->and(Payment::query()->count())->toBe(0)
        ->and(FinancialTransaction::query()->where('type', 'pos_receipt')->count())->toBe(0)
        ->and(Customer::query()->where('phone', '+8801819552310')->sole()->name)->toBe('রহিমা আক্তার');

    $this->getJson("/api/v1/admin/pos/sales/{$response->json('id')}/receipt")->assertOk()
        ->assertJsonPath('due_amount', '600.00')
        ->assertJsonCount(0, 'payments');
    dueInvariants();
});

it('refuses due without a phone, without a name, for the walk-in, and without pos.sell_on_due', function (): void {
    $tenders = [['method' => 'cash', 'amount' => '850'], ['method' => 'due', 'amount' => '1000']];

    // No phone: the anonymous walk-in can never owe.
    PosKit::charge($this->session, [$this->toy->id => 1], $tenders, '1850.00')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.1.method')
        ->assertJsonPath('errors.0.message', 'Due needs a known customer — add the phone and name.');
    // A new phone and no name.
    PosKit::charge($this->session, [$this->toy->id => 1], $tenders, '1850.00', ['customer_phone' => PosKit::PHONE])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.1.method');
    // A known phone whose only name is the "Walk-in customer" placeholder, still no name.
    PosKit::charge($this->session, [$this->small->id => 1], [['method' => 'cash', 'amount' => '600']], '600.00', ['customer_phone' => PosKit::PHONE])->assertCreated();
    PosKit::charge($this->session, [$this->toy->id => 1], $tenders, '1850.00', ['customer_phone' => PosKit::PHONE])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.1.method');
    expect(Order::query()->where('due_total', '>', 0)->count())->toBe(0);

    // The name typed now replaces the placeholder and the due sale goes through.
    PosKit::charge($this->session, [$this->toy->id => 1], $tenders, '1850.00', ['customer_phone' => PosKit::PHONE, 'customer_name' => 'Rahima Akter'])->assertCreated();
    // Known and named now: no name needed on the next sale.
    PosKit::charge($this->session, [$this->small->id => 1], [['method' => 'due', 'amount' => '600']], '600.00', ['customer_phone' => PosKit::PHONE])->assertCreated();

    // A cashier without pos.sell_on_due (order_operator), even when the request sends `due`.
    $cashier = User::factory()->withRole(Role::OrderOperator)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($cashier, 'web');
    $drawer = PosKit::open($this->branch, $cashier, register: 'Register 2');
    PosKit::charge($drawer, [$this->toy->id => 1], $tenders, '1850.00', ['customer_phone' => PosKit::PHONE])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.1.method')
        ->assertJsonPath('errors.0.message', 'You cannot sell on due. Ask a manager.');
    dueInvariants();
});

it('refuses a due tender that would push the customer over the limit', function (): void {
    Setting::put(PosDueSettings::KEY, '1500.00', SettingGroup::Checkout);
    app(PosDueSettings::class)->refresh();

    dueSale($this->session, $this->toy->id, '1850.00', '1000.00')->assertCreated();
    dueSale($this->session, $this->small->id, '600.00', '600.00')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.0.method')
        ->assertJsonPath('errors.0.message', 'Over the due limit — ৳500.00 available.');
    // Exactly up to the limit is fine.
    dueSale($this->session, $this->small->id, '600.00', '500.00')->assertCreated();
    expect(Order::query()->sum('due_total'))->toEqual(1500);
    dueInvariants();
});

it('quotes the due part and its errors, and shows the context and customer due', function (): void {
    $this->getJson('/api/v1/admin/pos/context')->assertOk()
        ->assertJsonPath('due_limit', '5000.00')
        ->assertJsonPath('tenders.4.method', 'due')
        ->assertJsonPath('tenders.4.requires_customer', true)
        ->assertJsonPath('tenders.4.requires_reference', false)
        ->assertJsonPath('tenders.4.gives_change', false)
        ->assertJsonPath('tenders.0.requires_customer', false);

    $quote = fn (array $extra) => $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/quote", [
        'lines' => PosKit::lines([$this->toy->id => 1]),
        'tenders' => [['method' => 'cash', 'amount' => '1000'], ['method' => 'due', 'amount' => '850']],
        ...$extra,
    ]);
    $quote(['customer_phone' => PosKit::PHONE, 'customer_name' => 'Rahima Akter'])->assertOk()
        ->assertJsonPath('tenders.tendered', '1000.00')
        ->assertJsonPath('tenders.due', '850.00')
        ->assertJsonPath('tenders.remaining', '0.00')
        ->assertJsonPath('tenders.errors', [])
        ->assertJsonPath('chargeable', true);
    $quote(['customer_phone' => PosKit::PHONE])->assertOk()
        ->assertJsonPath('tenders.errors.0.field', 'tenders.1.method')
        ->assertJsonPath('chargeable', false);
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/quote", [
        'lines' => PosKit::lines([$this->toy->id => 1]), 'tenders' => [['method' => 'cash', 'amount' => '2000']],
    ])->assertOk()->assertJsonPath('tenders.due', '0.00')->assertJsonPath('tenders.change', '150.00');

    dueSale($this->session, $this->toy->id, '1850.00', '1200.00')->assertCreated();
    $this->getJson('/api/v1/admin/pos/customers?phone='.PosKit::PHONE)->assertOk()
        ->assertJsonPath('customer.open_due', '1200.00')
        ->assertJsonPath('customer.due_limit', '5000.00')
        ->assertJsonPath('customer.due_available', '3800.00');

    // An order_operator's register never lists due.
    $cashier = User::factory()->withRole(Role::OrderOperator)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($cashier, 'web');
    $tenders = $this->getJson('/api/v1/admin/pos/context')->assertOk()->json('tenders');
    expect(array_column($tenders, 'method'))->toBe(['cash', 'bkash', 'nagad', 'card']);
});

it('collects due FIFO across two orders, as payments booked like sale tenders', function (): void {
    $first = dueSale($this->session, $this->toy->id, '1850.00', '1000.00')->assertCreated()->json('order_id');
    $second = dueSale($this->session, $this->small->id, '600.00', '600.00')->assertCreated()->json('order_id');

    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/due-collections/quote", [
        'customer_phone' => PosKit::PHONE, 'tenders' => [['method' => 'cash', 'amount' => '1200']],
    ])->assertOk()
        ->assertJsonPath('customer.name', 'Rahima Akter')
        ->assertJsonPath('customer.phone', PosKit::PHONE)
        ->assertJsonPath('open_due', '1600.00')
        ->assertJsonPath('orders.0.order_id', $first)
        ->assertJsonPath('orders.0.applied', '1000.00')
        ->assertJsonPath('orders.1.due', '600.00')
        ->assertJsonPath('orders.1.applied', '200.00')
        ->assertJsonPath('tenders.remaining', '400.00')
        ->assertJsonPath('tenders.change', '0.00')
        ->assertJsonPath('chargeable', true);

    collectDue($this->session, [['method' => 'cash', 'amount' => '1200']])->assertCreated()
        ->assertJsonPath('collected', '1200.00')
        ->assertJsonPath('remaining_due', '400.00')
        ->assertJsonPath('change_due', '0.00')
        ->assertJsonPath('orders.0.applied', '1000.00')
        ->assertJsonPath('orders.1.applied', '200.00');

    $a = Order::query()->findOrFail($first);
    $b = Order::query()->findOrFail($second);
    expect($a->payment_status)->toBe(OrderPaymentStatus::Paid)
        ->and((string) $a->due_total)->toBe('0.00')
        ->and($b->payment_status)->toBe(OrderPaymentStatus::PartiallyPaid)
        ->and((string) $b->due_total)->toBe('400.00')
        ->and(PosDueCollectionAllocation::query()->count())->toBe(2);

    // Cash: 850 at the first sale + 1000 + 200 collected; still never revenue.
    $receipts = FinancialTransaction::query()->where('type', 'pos_receipt')->get();
    expect($receipts->sum(fn ($r) => (float) (string) $r->amount))->toBe(2050.0)
        ->and($receipts->every(fn ($r) => $r->is_cash_movement && ! $r->is_recognised_revenue))->toBeTrue();

    // The first sale's receipt still lists only its own tender.
    $saleId = App\Domain\Pos\Models\PosSale::query()->where('order_id', $first)->value('id');
    $this->getJson("/api/v1/admin/pos/sales/{$saleId}/receipt")->assertOk()->assertJsonCount(1, 'payments');
    $this->getJson('/api/v1/admin/pos/customers?phone='.PosKit::PHONE)->assertJsonPath('customer.open_due', '400.00');
    dueInvariants();
});

it('gives change from cash on a collection and refuses non-cash above the open due', function (): void {
    dueSale($this->session, $this->small->id, '600.00', '600.00')->assertCreated();

    collectDue($this->session, [['method' => 'bkash', 'amount' => '700', 'reference' => 'BK1']])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders');
    collectDue($this->session, [['method' => 'due', 'amount' => '100']])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.0.method');
    collectDue($this->session, [['method' => 'nagad', 'amount' => '100']])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.0.reference');
    collectDue($this->session, [['method' => 'cash', 'amount' => '100']], phone: '01712345678')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'customer_phone');
    expect(PosDueCollection::query()->count())->toBe(0);

    collectDue($this->session, [['method' => 'bkash', 'amount' => '200', 'reference' => 'BK2'], ['method' => 'cash', 'amount' => '1000']])
        ->assertCreated()
        ->assertJsonPath('collected', '600.00')
        ->assertJsonPath('tendered_total', '1200.00')
        ->assertJsonPath('cash_tendered', '1000.00')
        ->assertJsonPath('change_due', '600.00')
        ->assertJsonPath('remaining_due', '0.00');
    expect(Payment::query()->where('method', 'cash')->sole()->amount->toDecimal())->toBe('400.00');

    // Nothing left to collect.
    collectDue($this->session, [['method' => 'cash', 'amount' => '100']])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'customer_phone');
    dueInvariants();
});

it('is idempotent: the same key twice is one collection', function (): void {
    dueSale($this->session, $this->small->id, '600.00', '600.00')->assertCreated();

    $first = collectDue($this->session, [['method' => 'cash', 'amount' => '300']], 'collect-key-0001')->assertCreated();
    $second = collectDue($this->session, [['method' => 'cash', 'amount' => '300']], 'collect-key-0001')->assertCreated();
    cache()->flush();
    $third = collectDue($this->session, [['method' => 'cash', 'amount' => '300']], 'collect-key-0001')->assertCreated();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and($third->json('id'))->toBe($first->json('id'))
        ->and(PosDueCollection::query()->count())->toBe(1)
        ->and((string) Order::query()->sole()->due_total)->toBe('300.00');

    $this->flushHeaders()->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/due-collections", [
        'customer_phone' => PosKit::PHONE, 'tenders' => [['method' => 'cash', 'amount' => '100']],
    ])->assertStatus(400);
    dueInvariants();
});

it('counts collected cash in the collecting drawer and never a sale\'s due', function (): void {
    dueSale($this->session, $this->toy->id, '1850.00', '1000.00')->assertCreated();

    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}")->assertOk()
        ->assertJsonPath('totals.expected_cash', '5850.00')   // float 5000 + 850 cash, not the 1000 due
        ->assertJsonPath('totals.sales_total', '850.00')
        ->assertJsonPath('totals.due_sold', '1000.00')
        ->assertJsonPath('totals.due_collected', '0.00');

    collectDue($this->session, [['method' => 'cash', 'amount' => '300'], ['method' => 'card', 'amount' => '200', 'reference' => 'APR-9']])->assertCreated();
    $totals = $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}")->assertOk()->json('totals');
    expect($totals['expected_cash'])->toBe('6150.00')
        ->and($totals['sales_total'])->toBe('850.00')
        ->and($totals['due_collected'])->toBe('500.00')
        ->and($totals['due_collections_count'])->toBe(1)
        ->and(collect($totals['by_method'])->pluck('amount', 'method')->all())->toBe(['cash' => '1150.00', 'card' => '200.00']);

    // Collected on another manager's drawer: counted there, not here — even though it pays an order sold here.
    $other = User::factory()->withRole(Role::Manager)->create()->refresh();
    auth()->forgetGuards();
    $this->actingAs($other, 'web');
    $drawer = PosKit::open($this->branch, $other, '1000.00', 'Register 2');
    collectDue($drawer, [['method' => 'cash', 'amount' => '500']])->assertCreated()->assertJsonPath('remaining_due', '0.00');

    $this->getJson("/api/v1/admin/pos/sessions/{$drawer->id}")->assertOk()
        ->assertJsonPath('totals.expected_cash', '1500.00')
        ->assertJsonPath('totals.sales_total', '0.00');
    auth()->forgetGuards();
    $this->actingAs($this->manager, 'web');
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}")->assertOk()->assertJsonPath('totals.expected_cash', '6150.00');

    // Close: expected cash includes the collection.
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/close", ['counted_cash' => '6150.00'])->assertOk()
        ->assertJsonPath('expected_cash', '6150.00')->assertJsonPath('over_short', '0.00');
    dueInvariants();
});

it('searches customers by name or phone digits, never the walk-in, with their due', function (): void {
    dueSale($this->session, $this->small->id, '600.00', '600.00')->assertCreated();
    PosKit::charge($this->session, [$this->small->id => 1], [['method' => 'cash', 'amount' => '600']], '600.00')->assertCreated(); // walk-in
    PosKit::charge($this->session, [$this->small->id => 1], [['method' => 'cash', 'amount' => '600']], '600.00', ['customer_phone' => '01712000111', 'customer_name' => 'করিম উদ্দিন'])->assertCreated();

    $this->getJson('/api/v1/admin/pos/customers/search?q=rahima')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Rahima Akter')
        ->assertJsonPath('data.0.phone', PosKit::PHONE)
        ->assertJsonPath('data.0.open_due', '600.00')
        ->assertJsonPath('data.0.due_limit', '5000.00')
        ->assertJsonPath('data.0.due_available', '4400.00');
    $this->getJson('/api/v1/admin/pos/customers/search?q='.rawurlencode('করিম'))->assertOk()->assertJsonPath('data.0.phone', '01712000111')->assertJsonPath('data.0.open_due', '0.00');
    $this->getJson('/api/v1/admin/pos/customers/search?q=552310')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/pos/customers/search?q=%2B8801712')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'করিম উদ্দিন');
    $this->getJson('/api/v1/admin/pos/customers/search?q=walk')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/pos/customers/search?q=01000000000')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/admin/pos/customers/search?q=r')->assertStatus(422);

    // Online-only shoppers never appear: the counter lists customers who bought at the POS.
    $online = Customer::factory()->create(['name' => 'Rahima Online', 'phone' => '+8801911000222', 'last_order_at' => now()->addDay()]);
    Order::factory()->create(['customer_id' => $online->id]);
    $this->getJson('/api/v1/admin/pos/customers/search?q=rahima')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Rahima Akter');
    $this->getJson('/api/v1/admin/pos/customers/search?q=01911')->assertOk()->assertJsonCount(0, 'data');

    // No term: the most recent customers, latest order first, never the walk-in row.
    $this->getJson('/api/v1/admin/pos/customers/search')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.phone', '01712000111')
        ->assertJsonPath('data.1.phone', PosKit::PHONE);
});
