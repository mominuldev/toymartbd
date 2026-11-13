<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Reconciliation\OrderBreakdownRow;
use App\Domain\Finance\Reconciliation\OrderProfitBreakdown;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderReturn;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\ExpenseCategoriesSeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Checkout\Support\CheckoutKit;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Orders\Support as OrderSupport;
use Tests\Feature\Purchasing\PurchasingKit;

/*
 * REHEARSAL of the Phase 5 gate (docs/ops/PROFIT-RECONCILIATION.md): "net profit correct to
 * the taka … verified by hand against a spreadsheet on at least ten real orders".
 *
 * Ten orders go through the real flow — storefront checkout over HTTP (cart, coupon,
 * delivery charge from the resolver, cost snapshot), admin confirm / ship / deliver /
 * cancel, returns inspected and refunds approved and paid over the admin API, expenses
 * entered by an accountant and approved by a manager. Then three things must agree to the
 * poisha for every order:
 *
 *   1. the breakdown's order side (source tables),
 *   2. its ledger side (financial_transactions),
 *   3. the numbers worked out BY HAND below, line by line, the way the owner's
 *      accountant would on paper.
 *
 * Placeholder delivery rates (DeliverySeeder): Dhaka ৳70, outside Dhaka ৳130, +৳20 per
 * started kg above 1 kg (Steadfast), free at a merchandise total ≥ ৳3,000.
 */

const R_DAY = '2026-11-20';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config([
        'services.storefront.url' => '', 'services.storefront.revalidate_secret' => '',
        'risk.first_order_hold_threshold' => '100000.00',
        'returns.approval_threshold' => '2000.00',
    ]);
    $this->travelTo(CarbonImmutable::parse(R_DAY.' 11:00:00', 'Asia/Dhaka'));
    $this->seed([RolesAndPermissionsSeeder::class, GeographySeeder::class, DeliverySeeder::class, ExpenseCategoriesSeeder::class]);
    $this->storefront = CartTestKit::storefrontToken();
    $this->operator = User::factory()->withRole(Role::SuperAdmin)->create()->refresh();

    // Price / snapshot cost / weight. B's cost has poisha in it on purpose.
    $this->a = CartTestKit::variant('1000.00', 20, variant: ['cost_price' => '600.00', 'name' => 'Red']);
    $this->b = CartTestKit::variant('450.00', 20, variant: ['cost_price' => '250.50', 'name' => 'Blue']);
    // C is BOUGHT, not opened: 20 × ৳1,400 + ৳2,000 freight → landed ৳1,500 a unit, which
    // becomes its cost and then the sale's cost_price_snapshot (the gate's "a purchase can
    // be received, its landed cost flows into a sale").
    $this->c = CartTestKit::variant('2200.00', 0, variant: ['cost_price' => '0.00', 'weight_grams' => 2500, 'name' => 'Big']);
    $purchase = PurchasingKit::purchase([[$this->c, 20, '1400.00']]);
    PurchasingKit::receive($purchase, [$purchase->items()->value('id') => 20], freight: '2000.00');
    expect($this->c->refresh()->cost_price->toDecimal())->toBe('1500.00');
    $this->d = CartTestKit::variant('750.00', 20, variant: ['cost_price' => '400.00', 'name' => 'Green']);
    Coupon::factory()->fixed('150.00')->create(['code' => 'FLAT150']);
});

/**
 * Storefront checkout over HTTP. $dhaka = district-level Dhaka address (metro, ৳70).
 *
 * @param  array<int, int>  $lines
 */
function rhPlace(array $lines, string $phone, bool $dhaka = false, ?string $coupon = null): Order
{
    $cart = CheckoutKit::cart($lines, $coupon);
    $overrides = ['phone' => $phone] + ($dhaka ? ['district_id' => 47, 'upazila_id' => null, 'area' => 'ধানমন্ডি'] : []);

    test()->withToken(test()->storefront)->postJson('/api/v1/storefront/checkout', CheckoutKit::payload($cart, $overrides), [
        CartTestKit::HEADER => $cart,
        'Idempotency-Key' => (string) Str::uuid(),
    ])->assertCreated();

    return Order::query()->latest('id')->firstOrFail();
}

function rhAsOperator(): void
{
    auth()->forgetGuards();
    test()->actingAs(test()->operator, 'web');
}

function rhConfirm(Order $order): void
{
    rhAsOperator();
    test()->postJson("/api/v1/admin/orders/{$order->id}/confirm")->assertOk();
}

function rhShip(Order $order): void
{
    rhConfirm($order);
    test()->postJson("/api/v1/admin/orders/{$order->id}/ship", ['courier_name' => 'Steadfast (portal)'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
}

function rhDeliver(Order $order): Order
{
    rhShip($order);
    test()->postJson("/api/v1/admin/orders/{$order->id}/deliver", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    return $order->refresh()->load('items');
}

/**
 * Open a return, inspect it, approve and pay the refund — the admin's whole returns flow.
 *
 * @param  list<array{item: int, quantity: int, damaged: int}>  $lines
 */
function rhReturn(Order $order, array $lines, bool $refundShipping = false): void
{
    rhAsOperator();
    test()->postJson("/api/v1/admin/orders/{$order->id}/returns", [
        'items' => array_map(static fn (array $l): array => ['order_item_id' => $l['item'], 'quantity' => $l['quantity']], $lines),
        'reason' => 'defective',
        'refund_shipping' => $refundShipping,
    ])->assertCreated();
    $return = OrderReturn::query()->latest('id')->firstOrFail();

    test()->postJson("/api/v1/admin/returns/{$return->id}/inspect", [
        'items' => array_map(static fn (array $l): array => ['order_item_id' => $l['item'], 'resellable' => $l['quantity'] - $l['damaged'], 'damaged' => $l['damaged']], $lines),
    ])->assertOk();

    $refund = $return->refresh()->refund;
    expect($refund)->not->toBeNull();
    test()->postJson("/api/v1/admin/refunds/{$refund->id}/approve")->assertOk();
    test()->postJson("/api/v1/admin/refunds/{$refund->id}/pay", ['method' => 'bkash', 'reference' => 'BK'.$refund->id], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
}

function rhItem(Order $order, ProductVariant $variant): int
{
    return $order->items->firstOrFail(static fn ($i): bool => $i->variant_id === $variant->id)->id;
}

/** An expense: an accountant enters it, a manager approves it (segregation of duties). */
function rhExpense(string $category, string $amount, ?int $orderId = null): void
{
    auth()->forgetGuards();
    test()->actingAs(User::factory()->withRole(Role::Accountant)->create()->refresh(), 'web');
    $id = test()->postJson('/api/v1/admin/finance/expenses', [
        'category_id' => (int) ExpenseCategory::query()->where('name', $category)->value('id'),
        'amount' => $amount,
        'expense_date' => R_DAY,
        'payment_method' => 'cash',
        'vendor' => 'মিরপুর গুদাম',
        'order_id' => $orderId,
    ])->assertCreated()->json('id');

    auth()->forgetGuards();
    test()->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
    test()->postJson("/api/v1/admin/finance/expenses/{$id}/approve")->assertOk();
}

/** @return array<string, string> component → decimal */
function rhDecimals(array $components): array
{
    return array_map(static fn ($m): string => $m->toDecimal(), $components);
}

it('rehearses the ten-order hand check: source tables, ledger and hand arithmetic agree to the poisha', function (): void {
    // 1 · Plain COD, delivered. Outside Dhaka ৳130. Plus ৳25 of packaging booked against it.
    $o1 = rhDeliver(rhPlace([$this->a->id => 1], '01711000001'));
    rhExpense('Packaging', '25.00', $o1->id);

    // 2 · Coupon FLAT150 on two lines (A 1000 + D 750), outside Dhaka ৳130.
    $o2 = rhDeliver(rhPlace([$this->a->id => 1, $this->d->id => 1], '01711000002', coupon: 'FLAT150'));

    // 3 · Delivery-charge variant: Dhaka city ৳70. B × 2.
    $o3 = rhDeliver(rhPlace([$this->b->id => 2], '01811000003', dhaka: true));

    // 4 · Delivery-charge variant: heavy parcel, 2.5 kg outside Dhaka = 130 + 2 × 20 = ৳170.
    $o4 = rhDeliver(rhPlace([$this->c->id => 1], '01911000004'));

    // 5 · Multi-line, free delivery: A×2 + B + D = 3200 ≥ 3000 → ৳0.
    $o5 = rhDeliver(rhPlace([$this->a->id => 2, $this->b->id => 1, $this->d->id => 1], '01611000005'));

    // 6 · Partial return with refund: A + B×2 (+130); one B comes back resellable.
    $o6 = rhDeliver(rhPlace([$this->a->id => 1, $this->b->id => 2], '01511000006'));
    rhReturn($o6, [['item' => rhItem($o6, $this->b), 'quantity' => 1, 'damaged' => 0]]);

    // 7 · Full return: D (+130), everything back resellable, delivery charge refunded too.
    $o7 = rhDeliver(rhPlace([$this->d->id => 1], '01311000007'));
    rhReturn($o7, [['item' => rhItem($o7, $this->d), 'quantity' => 1, 'damaged' => 0]], refundShipping: true);

    // 8 · Damaged-item write-off: A×2 (+130); one A comes back broken.
    $o8 = rhDeliver(rhPlace([$this->a->id => 2], '01411000008'));
    rhReturn($o8, [['item' => rhItem($o8, $this->a), 'quantity' => 1, 'damaged' => 1]]);

    // 9 · Cancelled before shipping (confirmed, then the customer cancels).
    $o9 = rhPlace([$this->b->id => 1], '01711000009');
    rhConfirm($o9);
    $this->postJson("/api/v1/admin/orders/{$o9->id}/cancel", ['reason' => 'customer_request'])->assertOk();

    // 10 · Still in transit: shipped (stock consumed), not delivered → nothing recognised.
    $o10 = rhPlace([$this->c->id => 1], '01711000010', dhaka: true);
    rhShip($o10);

    // An expense in the period that belongs to no order: November rent, ৳5,000.
    rhExpense('Rent', '5000.00');

    /*
     * BY HAND. Components in their natural direction (costs positive); net =
     * revenue − discount + delivery − cogs + cogs_reversed − write_off − refunds
     *   − courier − gateway − order_expenses.
     */
    $z = '0.00';
    $hand = [
        // 1000 + 130 − 600 − 25 = 505
        $o1->id => ['item_revenue' => '1000.00', 'discount' => $z, 'delivery_charge' => '130.00', 'cogs' => '600.00', 'cogs_reversed' => $z, 'write_off' => $z, 'refunds' => $z, 'order_expenses' => '25.00', 'net' => '505.00'],
        // 1750 − 150 + 130 − (600 + 400) = 730
        $o2->id => ['item_revenue' => '1750.00', 'discount' => '150.00', 'delivery_charge' => '130.00', 'cogs' => '1000.00', 'cogs_reversed' => $z, 'write_off' => $z, 'refunds' => $z, 'order_expenses' => $z, 'net' => '730.00'],
        // 900 + 70 − 2 × 250.50 = 469
        $o3->id => ['item_revenue' => '900.00', 'discount' => $z, 'delivery_charge' => '70.00', 'cogs' => '501.00', 'cogs_reversed' => $z, 'write_off' => $z, 'refunds' => $z, 'order_expenses' => $z, 'net' => '469.00'],
        // 2200 + 170 − 1500 (landed cost) = 870
        $o4->id => ['item_revenue' => '2200.00', 'discount' => $z, 'delivery_charge' => '170.00', 'cogs' => '1500.00', 'cogs_reversed' => $z, 'write_off' => $z, 'refunds' => $z, 'order_expenses' => $z, 'net' => '870.00'],
        // 3200 + 0 − (1200 + 250.50 + 400) = 1349.50
        $o5->id => ['item_revenue' => '3200.00', 'discount' => $z, 'delivery_charge' => $z, 'cogs' => '1850.50', 'cogs_reversed' => $z, 'write_off' => $z, 'refunds' => $z, 'order_expenses' => $z, 'net' => '1349.50'],
        // 1900 + 130 − (600 + 501) + 250.50 − 450 = 729.50
        $o6->id => ['item_revenue' => '1900.00', 'discount' => $z, 'delivery_charge' => '130.00', 'cogs' => '1101.00', 'cogs_reversed' => '250.50', 'write_off' => $z, 'refunds' => '450.00', 'order_expenses' => $z, 'net' => '729.50'],
        // 750 + 130 − 400 + 400 − (750 + 130) = 0
        $o7->id => ['item_revenue' => '750.00', 'discount' => $z, 'delivery_charge' => '130.00', 'cogs' => '400.00', 'cogs_reversed' => '400.00', 'write_off' => $z, 'refunds' => '880.00', 'order_expenses' => $z, 'net' => '0.00'],
        // 2000 + 130 − 1200 + 600 − 600 − 1000 = −70
        $o8->id => ['item_revenue' => '2000.00', 'discount' => $z, 'delivery_charge' => '130.00', 'cogs' => '1200.00', 'cogs_reversed' => '600.00', 'write_off' => '600.00', 'refunds' => '1000.00', 'order_expenses' => $z, 'net' => '-70.00'],
        // cancelled before shipping: nothing recognised
        $o9->id => ['item_revenue' => $z, 'discount' => $z, 'delivery_charge' => $z, 'cogs' => $z, 'cogs_reversed' => $z, 'write_off' => $z, 'refunds' => $z, 'order_expenses' => $z, 'net' => $z],
        // in transit: nothing recognised yet (revenue on delivery, rule 10)
        $o10->id => ['item_revenue' => $z, 'discount' => $z, 'delivery_charge' => $z, 'cogs' => $z, 'cogs_reversed' => $z, 'write_off' => $z, 'refunds' => $z, 'order_expenses' => $z, 'net' => $z],
    ];

    // The orders as placed: the delivery charge the resolver put on each, and the coupon.
    expect($o3->refresh()->shipping_charge->toDecimal())->toBe('70.00')
        ->and($o4->refresh()->shipping_charge->toDecimal())->toBe('170.00')
        ->and($o5->refresh()->shipping_charge->toDecimal())->toBe('0.00')
        ->and($o2->refresh()->discount_total->toDecimal())->toBe('150.00')
        ->and($o10->refresh()->shipping_charge->toDecimal())->toBe('110.00'); // Dhaka 70 + 2 × 20

    $breakdown = app(OrderProfitBreakdown::class);
    $ids = $breakdown->selectOrders(R_DAY, R_DAY);
    expect($ids)->toBe(array_keys($hand));

    $rows = collect($breakdown->rows($ids))->keyBy(static fn (OrderBreakdownRow $r): int => $r->orderId);
    foreach ($hand as $orderId => $want) {
        /** @var OrderBreakdownRow $row */
        $row = $rows[$orderId];
        $net = $want['net'];
        unset($want['net']);
        $expected = [...$want, 'courier_charges' => $z, 'gateway_fees' => $z, 'other' => $z];
        ksort($expected);
        $orderSide = rhDecimals($row->order);
        $ledgerSide = rhDecimals($row->ledger);
        ksort($orderSide);
        ksort($ledgerSide);

        expect($orderSide)->toBe($expected, "order side of {$row->number}")
            ->and($ledgerSide)->toBe($expected, "ledger side of {$row->number}")
            ->and($row->orderNet->toDecimal())->toBe($net, "order net of {$row->number}")
            ->and($row->ledgerNet->toDecimal())->toBe($net, "ledger net of {$row->number}")
            ->and(($row->projectionNet?->toDecimal()) ?? $z)->toBe($net, "projection of {$row->number}")
            ->and($row->agrees())->toBeTrue();
    }
    expect($rows[$o9->id]->recognised)->toBeFalse()->and($rows[$o10->id]->recognised)->toBeFalse()
        ->and($rows[$o8->id]->recognised)->toBeTrue();

    // The period: Σ order nets 4583 + rent −5000 = −417, and the P&L report says the same.
    $period = $breakdown->period(ReportPeriod::of(R_DAY, R_DAY));
    expect($period['order_scoped']->toDecimal())->toBe('4583.00')
        ->and($period['other']->toDecimal())->toBe('-5000.00')
        ->and(rhDecimals($period['other_by_type']))->toBe(['rent' => '-5000.00'])
        ->and($period['ledger_net_profit']->toDecimal())->toBe('-417.00')
        ->and($period['pnl_net_profit']->toDecimal())->toBe('-417.00')
        ->and($period['agrees'])->toBeTrue();

    // Stock: cancelled order released, in-transit consumed, damaged unit written off.
    $level = static fn (ProductVariant $v): array => StockLevel::query()->where('variant_id', $v->id)->get(['on_hand', 'reserved'])->map(static fn ($l): array => [$l->on_hand, $l->reserved])->sole();
    // A: 20 − (1+1+2+1+2) sold + 1 back (o8) − 1 damaged = 13
    expect($level($this->a))->toBe([13, 0])
        // B: 20 − (2+1+2) sold + 1 back (o6) = 16; o9's unit released, never sold
        ->and($level($this->b))->toBe([16, 0])
        // C: 20 − 1 (o4) − 1 (o10, shipped) = 18
        ->and($level($this->c))->toBe([18, 0])
        // D: 20 − (1+1+1) + 1 back (o7) = 18
        ->and($level($this->d))->toBe([18, 0]);

    // Both build invariants, and the commands the owner runs.
    OrderSupport::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
    expect(Artisan::call('toymart:finance:verify'))->toBe(0)
        ->and(Artisan::call('toymart:inventory:reconcile'))->toBe(0);

    // The export the accountant opens says the same, row for row.
    $path = storage_path('framework/testing/rehearsal-'.Str::random(8).'.csv');
    expect(Artisan::call('toymart:finance:order-breakdown', ['--from' => R_DAY, '--to' => R_DAY, '--output' => $path]))->toBe(0);
    $lines = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES) ?: []);
    unlink($path);
    $header = array_map(static fn (string $h): string => ltrim($h, "\u{FEFF}"), $lines[0]);
    $byNumber = [];
    foreach (array_slice($lines, 1) as $line) {
        $byNumber[$line[0]] = array_combine($header, $line);
    }
    foreach ($hand as $orderId => $want) {
        $csv = $byNumber[$rows[$orderId]->number];
        expect($csv['order_net_profit'])->toBe($want['net'])
            ->and($csv['ledger_net_profit'])->toBe($want['net'])
            ->and($csv['difference'])->toBe('0.00')
            ->and($csv['result'])->toBe('OK');
    }
    expect($byNumber['TOTAL']['order_net_profit'])->toBe('4583.00')
        ->and($byNumber['TOTAL']['ledger_net_profit'])->toBe('4583.00')
        ->and($byNumber['TOTAL']['result'])->toBe('OK');
});
