<?php

declare(strict_types=1);

use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\Promotion;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderSource;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pos\Jobs\RecordPosTendersInLedger;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Services\PosCustomers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Orders\Support;
use Tests\Feature\Pos\PosKit;

/*
 * Charge (Figma B · 33): one Action, one transaction — branch stock with ordered locks,
 * the order (source pos, delivered at the counter), cost snapshots, split manual tenders,
 * server-side change, revenue recognised on the sale day and cash kept apart, idempotent.
 * Both invariants after every path.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->branch = PosKit::branch();
    $this->cashier = PosKit::cashier();
    $this->session = PosKit::open($this->branch, $this->cashier);
    // 10 at the branch, 50 at the default warehouse: a sale must only touch the branch.
    $this->brick = PosKit::variant($this->branch, 10, '1850.00', '1100.00', atDefault: 50, barcode: '8901234567001');
    $this->cube = PosKit::variant($this->branch, 12, '320.00', '150.00');
});

function posInvariants(): void
{
    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0]);
}

it('sells at the branch: order delivered at the counter, stock out of the branch only, change from cash', function (): void {
    $response = PosKit::charge($this->session, [$this->brick->id => 1, $this->cube->id => 2], [['method' => 'cash', 'amount' => '3000']], '2490.00')
        ->assertCreated()
        ->assertJsonPath('grand_total', '2490.00')
        ->assertJsonPath('tendered_total', '3000.00')
        ->assertJsonPath('cash_tendered', '3000.00')
        ->assertJsonPath('change_due', '510.00');

    $order = Order::query()->with(['items', 'payments'])->findOrFail($response->json('order_id'));
    expect($order->source)->toBe(OrderSource::Pos)
        ->and($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::Fulfilled)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Paid)
        ->and((string) $order->grand_total)->toBe('2490.00')
        ->and((string) $order->paid_total)->toBe('2490.00')
        ->and((string) $order->due_total)->toBe('0.00')
        ->and((string) $order->shipping_charge)->toBe('0.00')
        ->and($order->delivered_at)->not->toBeNull()
        ->and($order->created_by)->toBe($this->cashier->id)
        ->and($order->items)->toHaveCount(2)
        ->and($order->items->every(fn ($i) => $i->quantity_shipped === $i->quantity))->toBeTrue();

    // Cost snapshot from the branch's cost basis, written once (rule 7).
    expect((string) $order->items->firstWhere('variant_id', $this->brick->id)->cost_price_snapshot)->toStartWith('1100.00');

    // Cash kept = 3000 − 510 change = the total; one payment, paid, manual.
    expect($order->payments)->toHaveCount(1)
        ->and($order->payments[0]->method->value)->toBe('cash')
        ->and((string) $order->payments[0]->amount)->toBe('2490.00')
        ->and($order->payments[0]->status)->toBe(PaymentStatus::Paid)
        ->and($order->payments[0]->gateway_payload['driver'] ?? null)->toBe('pos_manual');

    // Stock: branch down, default warehouse untouched, sale_out movements at the branch.
    expect(PosKit::level($this->brick->id, $this->branch->id)->on_hand)->toBe(9)
        ->and(PosKit::level($this->brick->id, $this->branch->id)->reserved)->toBe(0)
        ->and(PosKit::level($this->cube->id, $this->branch->id)->on_hand)->toBe(10)
        ->and(PosKit::level($this->brick->id, StockLocation::defaultId())->on_hand)->toBe(50);
    $out = StockMovement::query()->where('type', StockMovementType::SaleOut->value)->get();
    expect($out)->toHaveCount(2)
        ->and($out->pluck('location_id')->unique()->all())->toBe([$this->branch->id])
        ->and($out->pluck('reference_type')->unique()->all())->toBe(['order']);

    posInvariants();
});

it('recognises revenue and COGS on the sale day and keeps cash out of the P&L', function (): void {
    $response = PosKit::charge($this->session, [$this->brick->id => 2], [['method' => 'cash', 'amount' => '3700']], '3700.00')->assertCreated();
    $orderId = $response->json('order_id');

    $rows = FinancialTransaction::query()->where('source_type', 'order')->where('source_id', $orderId)->get()->keyBy(fn ($r) => $r->type->value);
    expect((string) $rows['sale_revenue']->amount)->toBe('3700.00')
        ->and($rows['sale_revenue']->is_recognised_revenue)->toBeTrue()
        ->and($rows['sale_revenue']->is_cash_movement)->toBeFalse()
        ->and((string) $rows['cogs']->amount)->toBe('2200.00')
        ->and($rows['sale_revenue']->occurred_on->toDateString())->toBe(App\Support\DhakaDate::today());

    // The cash side: one pos_receipt per paid tender, cash and never revenue (rule 10).
    $cash = FinancialTransaction::query()->where('source_type', 'payment')->get();
    expect(LedgerEntryType::tryFrom(RecordPosTendersInLedger::TYPE))->toBe(LedgerEntryType::PosReceipt)
        ->and($cash)->toHaveCount(1)
        ->and($cash[0]->is_cash_movement)->toBeTrue()
        ->and($cash[0]->is_recognised_revenue)->toBeFalse()
        ->and((string) $cash[0]->amount)->toBe('3700.00');

    // Order financials equal the ledger: revenue 3700, gross profit 1500.
    expect((string) Order::query()->findOrFail($orderId)->financials?->gross_profit)->toBe('1500.00');
    posInvariants();
});

it('splits tenders: cash + bKash + card sum to the total, change only from cash', function (): void {
    $response = PosKit::charge($this->session, [$this->brick->id => 1, $this->cube->id => 1], [
        ['method' => 'bkash', 'amount' => '1000', 'reference' => 'BK7Q2A9'],
        ['method' => 'card', 'amount' => '500', 'reference' => 'APR-551'],
        ['method' => 'cash', 'amount' => '1000'],
    ], '2170.00')->assertCreated()
        ->assertJsonPath('tendered_total', '2500.00')
        ->assertJsonPath('change_due', '330.00');

    $payments = Payment::query()->where('order_id', $response->json('order_id'))->orderBy('id')->get();
    expect($payments->map(fn ($p) => [$p->method->value, (string) $p->amount, $p->gateway_reference])->all())->toBe([
        ['bkash', '1000.00', 'BK7Q2A9'],
        ['card', '500.00', 'APR-551'],
        ['cash', '670.00', null],
    ])
        ->and($payments->every(fn ($p) => $p->status === PaymentStatus::Paid))->toBeTrue()
        ->and(Order::query()->findOrFail($response->json('order_id'))->payment_status)->toBe(OrderPaymentStatus::Paid);

    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}")->assertOk()
        ->assertJsonPath('totals.expected_cash', '5670.00');
    posInvariants();
});

it('refuses tenders that do not settle: short, non-cash over the total, missing reference, two cash lines', function (): void {
    $lines = [$this->brick->id => 1];

    PosKit::charge($this->session, $lines, [['method' => 'cash', 'amount' => '1000']], '1850.00')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders');
    PosKit::charge($this->session, $lines, [['method' => 'card', 'amount' => '2000', 'reference' => 'X1']], '1850.00')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders');
    PosKit::charge($this->session, $lines, [['method' => 'nagad', 'amount' => '1850']], '1850.00')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.0.reference');
    PosKit::charge($this->session, $lines, [['method' => 'cash', 'amount' => '1000'], ['method' => 'cash', 'amount' => '850']], '1850.00')
        ->assertStatus(422);
    PosKit::charge($this->session, $lines, [['method' => 'cheque', 'amount' => '1850']], '1850.00')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'tenders.0.method');

    expect(Order::query()->count())->toBe(0)
        ->and(PosKit::level($this->brick->id, $this->branch->id)->on_hand)->toBe(10);
    posInvariants();
});

it('refuses a sale beyond the branch stock even when the warehouse has plenty — nothing written', function (): void {
    PosKit::charge($this->session, [$this->brick->id => 11], [['method' => 'cash', 'amount' => '20350']], '20350.00')
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'lines.'.$this->brick->id);

    expect(Order::query()->count())->toBe(0)
        ->and(PosKit::level($this->brick->id, $this->branch->id)->on_hand)->toBe(10)
        ->and(PosKit::level($this->brick->id, StockLocation::defaultId())->on_hand)->toBe(50);
    posInvariants();
});

it('answers price-changed when the total moved since the quote', function (): void {
    PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '2000']], '1800.00')
        ->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/price-changed');
    expect(Order::query()->count())->toBe(0);
});

it('is idempotent: the same key twice is one sale, one order, one stock movement', function (): void {
    $first = PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '2000']], '1850.00', key: 'charge-key-0001')->assertCreated();
    $second = PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '2000']], '1850.00', key: 'charge-key-0001')->assertCreated();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(PosSale::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(1)
        ->and(PosKit::level($this->brick->id, $this->branch->id)->on_hand)->toBe(9);

    // Past the middleware's cache (e.g. it expired): the database key still returns the first sale.
    cache()->flush();
    $third = PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '2000']], '1850.00', key: 'charge-key-0001')->assertCreated();
    expect($third->json('id'))->toBe($first->json('id'))->and(Order::query()->count())->toBe(1);

    // No key: 400.
    $this->flushHeaders()->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/sales", [
        'lines' => PosKit::lines([$this->brick->id => 1]), 'tenders' => [['method' => 'cash', 'amount' => '1850']], 'expected_total' => '1850.00',
    ])->assertStatus(400);
    posInvariants();
});

it('finds or creates the customer by phone, else books the anonymous walk-in row', function (): void {
    PosKit::charge($this->session, [$this->cube->id => 1], [['method' => 'cash', 'amount' => '320']], '320.00')->assertCreated();
    $walkIn = Customer::query()->where('phone', PosCustomers::WALK_IN_PHONE)->sole();
    expect($walkIn->name)->toBe('Walk-in customer');

    PosKit::charge($this->session, [$this->cube->id => 1], [['method' => 'cash', 'amount' => '320']], '320.00', ['customer_phone' => '01819 552 310', 'customer_name' => 'রুবিনা আক্তার'])->assertCreated();
    PosKit::charge($this->session, [$this->cube->id => 1], [['method' => 'cash', 'amount' => '320']], '320.00', ['customer_phone' => '+8801819552310', 'customer_name' => 'Someone else'])->assertCreated();

    $rubina = Customer::query()->where('phone', '+8801819552310')->sole();
    expect($rubina->name)->toBe('রুবিনা আক্তার')
        ->and($rubina->source->value)->toBe('walk_in')
        ->and(Order::query()->where('customer_id', $rubina->id)->count())->toBe(2);

    $this->getJson('/api/v1/admin/pos/customers?phone=01819552310')->assertOk()
        ->assertJsonPath('customer.name', 'রুবিনা আক্তার')
        ->assertJsonPath('phone', '01819552310');
    $this->getJson('/api/v1/admin/pos/customers?phone=01119552310')->assertStatus(422);
    posInvariants();
});

it('applies a coupon through the checkout pipeline, and needs a phone for any discount', function (): void {
    Coupon::factory()->fixed('100.00')->create(['code' => 'EID100']);

    PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '1750']], '1750.00', ['coupon_code' => 'EID100'])
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'customer_phone');

    $sale = PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '1750']], '1750.00', ['coupon_code' => 'EID100', 'customer_phone' => PosKit::PHONE])
        ->assertCreated();
    $order = Order::query()->findOrFail($sale->json('order_id'));
    expect((string) $order->coupon_discount)->toBe('100.00')
        ->and($order->coupon_code)->toBe('EID100')
        ->and(App\Domain\Marketing\Models\CouponUsage::query()->where('order_id', $order->id)->count())->toBe(1);
    posInvariants();
});

it('applies a running promotion only with a phone', function (): void {
    Promotion::factory()->create();

    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/quote", ['lines' => PosKit::lines([$this->brick->id => 1])])
        ->assertOk()
        ->assertJsonPath('grand_total', '1665.00')
        ->assertJsonPath('chargeable', false)
        ->assertJsonPath('blockers.0.field', 'customer_phone');

    PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '1665']], '1665.00', ['customer_phone' => PosKit::PHONE])
        ->assertCreated();
    posInvariants();
});

it('quotes every figure the register shows, with change and remaining from the server', function (): void {
    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/quote", [
        'lines' => PosKit::lines([$this->brick->id => 1, $this->cube->id => 2]),
        'tenders' => [['method' => 'cash', 'amount' => '4000']],
    ])->assertOk()
        ->assertJsonPath('subtotal', '2490.00')
        ->assertJsonPath('grand_total', '2490.00')
        ->assertJsonPath('item_count', 3)
        ->assertJsonPath('lines.1.total', '640.00')
        ->assertJsonPath('lines.0.available', 10)
        ->assertJsonPath('tenders.change', '1510.00')
        ->assertJsonPath('tenders.remaining', '0.00')
        ->assertJsonPath('chargeable', true);

    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/quote", [
        'lines' => PosKit::lines([$this->brick->id => 1]),
        'tenders' => [['method' => 'bkash', 'amount' => '1000', 'reference' => 'T1']],
    ])->assertOk()->assertJsonPath('tenders.remaining', '850.00')->assertJsonPath('chargeable', false);

    // Writes nothing.
    expect(Order::query()->count())->toBe(0)->and(PosKit::level($this->brick->id, $this->branch->id)->reserved)->toBe(0);
});

it('refuses a product that is not sold at the counter', function (): void {
    $this->cube->product->forceFill(['channel_pos' => false])->save();

    PosKit::charge($this->session, [$this->cube->id => 1], [['method' => 'cash', 'amount' => '320']], '320.00')
        ->assertStatus(422)->assertJsonPath('errors.0.field', 'lines.'.$this->cube->id);
});

it('lists the grid with branch stock, by barcode, by search and by category', function (): void {
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/catalog")->assertOk()
        ->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/catalog?barcode=8901234567001")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.variant_id', $this->brick->id)
        ->assertJsonPath('data.0.available', 10)
        ->assertJsonPath('data.0.price', '1850.00');
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/catalog?barcode=0000")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/catalog?q=".urlencode($this->cube->sku))->assertOk()
        ->assertJsonPath('data.0.variant_id', $this->cube->id);
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/catalog?category_id=".$this->brick->product->primary_category_id)->assertOk()
        ->assertJsonPath('data.0.variant_id', $this->brick->id);
    // Allowlisted filters only (rule 16).
    $this->getJson("/api/v1/admin/pos/sessions/{$this->session->id}/catalog?sort=price")->assertStatus(400);
});

it('prints a receipt with store details, masked phone, tenders and change', function (): void {
    $sale = PosKit::charge($this->session, [$this->brick->id => 1], [
        ['method' => 'bkash', 'amount' => '850', 'reference' => 'BK1'],
        ['method' => 'cash', 'amount' => '1500'],
    ], '1850.00', ['customer_phone' => PosKit::PHONE, 'customer_name' => 'Rubina Akter'])->assertCreated();

    $this->getJson("/api/v1/admin/pos/sales/{$sale->json('id')}/receipt")->assertOk()
        ->assertJsonPath('store.name_en', 'Smart Junior')
        ->assertJsonPath('branch.name', 'Chattogram · Agrabad')
        ->assertJsonPath('register', 'Register 1')
        ->assertJsonPath('cashier', $this->cashier->name)
        ->assertJsonPath('customer.name', 'Rubina Akter')
        ->assertJsonPath('customer.phone_masked', '018****2310')
        ->assertJsonPath('grand_total', '1850.00')
        ->assertJsonPath('payments.0.method', 'bkash')
        ->assertJsonPath('payments.1.amount', '1000.00')
        ->assertJsonPath('cash_tendered', '1500.00')
        ->assertJsonPath('change_due', '500.00')
        ->assertJsonPath('whatsapp.enabled', false);
});

it('ignores a free-delivery promotion at the counter: no row, no usage, no phone needed', function (): void {
    Promotion::factory()->rules(App\Domain\Marketing\Enums\PromotionType::FreeDelivery, ['min_subtotal' => '100.00'])->create(['name' => 'Free delivery over 100']);

    $this->postJson("/api/v1/admin/pos/sessions/{$this->session->id}/quote", ['lines' => PosKit::lines([$this->brick->id => 1])])
        ->assertOk()
        ->assertJsonCount(1, 'rows')
        ->assertJsonPath('rows.0.type', 'subtotal')
        ->assertJsonPath('blockers', []);

    $sale = PosKit::charge($this->session, [$this->brick->id => 1], [['method' => 'cash', 'amount' => '1850']], '1850.00', ['customer_phone' => PosKit::PHONE])->assertCreated();
    expect(App\Domain\Marketing\Models\PromotionUsage::query()->where('order_id', $sale->json('order_id'))->count())->toBe(0);
    posInvariants();
});
