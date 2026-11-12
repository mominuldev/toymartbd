<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Finance\Services\FinanceInvariant;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * Product / category / recommendation-source profitability (P5): the lines of orders
 * delivered in the period (Dhaka date of delivered_at), COGS from the frozen snapshot,
 * returns and completed refunds netted — checked against a hand computation.
 */

function profitOrder(OrderStatus $status, ?string $deliveredAtUtc): Order
{
    return Order::factory()->status($status)->create([
        'delivered_at' => $deliveredAtUtc === null ? null : CarbonImmutable::parse($deliveredAtUtc, 'UTC'),
    ]);
}

function profitLine(Order $order, Product $product, array $attributes): OrderItem
{
    $variant = $product->variants()->firstOrFail();

    return OrderItem::factory()->create([
        'order_id' => $order->id,
        'variant_id' => $variant->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'product_name_bn' => $product->name_bn,
        'sku' => $variant->sku,
        ...$attributes,
    ]);
}

function profitRefund(Order $order, OrderItem $item, string $amount, int $quantity, string $status = 'completed'): void
{
    $refundId = DB::table('refunds')->insertGetId([
        'order_id' => $order->id, 'amount' => $amount, 'reason' => 'Test', 'type' => 'partial', 'method' => 'cod',
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('refund_items')->insert(['refund_id' => $refundId, 'order_item_id' => $item->id, 'quantity' => $quantity, 'amount' => $amount]);
}

beforeEach(function (): void {
    $this->toys = Category::factory()->create(['name' => 'Toys']);
    $this->puzzles = Category::factory()->childOf($this->toys)->create(['name' => 'Puzzles']);
    $this->dolls = Category::factory()->create(['name' => 'Dolls']);
    $this->puzzle = Product::factory()->create(['name' => 'Wooden Puzzle', 'name_bn' => 'কাঠের পাজল', 'primary_category_id' => $this->puzzles->id]);
    $this->doll = Product::factory()->create(['name' => 'Rag Doll', 'name_bn' => 'কাপড়ের পুতুল', 'primary_category_id' => $this->dolls->id]);

    // A: delivered 3 Nov; 2 puzzles at 1,250 less 100 discount; a 300 goodwill refund (no return).
    $a = profitOrder(OrderStatus::Delivered, '2026-11-03 10:00:00');
    $aLine = profitLine($a, $this->puzzle, ['unit_price' => '1250.00', 'quantity' => 2, 'discount' => '100.00', 'line_total' => '2400.00', 'cost_price_snapshot' => '800.0000']);
    profitRefund($a, $aLine, '300.00', 0);

    // B: delivered 4 Nov, then 1 of 3 returned and refunded 1,200 — the same money, counted once.
    $b = profitOrder(OrderStatus::PartiallyReturned, '2026-11-04 09:00:00');
    $bLine = profitLine($b, $this->puzzle, ['unit_price' => '1200.00', 'quantity' => 3, 'quantity_shipped' => 3, 'quantity_returned' => 1, 'line_total' => '3600.00', 'cost_price_snapshot' => '810.5000', 'recommendation_source' => 'pdp_cross_sell']);
    profitRefund($b, $bLine, '1200.00', 1);

    // D: delivered 5 Nov; a rejected refund must not count.
    $d = profitOrder(OrderStatus::Completed, '2026-11-05 12:00:00');
    $dLine = profitLine($d, $this->doll, ['unit_price' => '700.00', 'quantity' => 1, 'line_total' => '700.00', 'cost_price_snapshot' => '350.0000']);
    profitRefund($d, $dLine, '100.00', 0, 'rejected');

    // C: delivered at 18:30 UTC on 8 Nov = 00:30 on 9 Nov in Dhaka → outside 2–8 Nov.
    $c = profitOrder(OrderStatus::Delivered, '2026-11-08 18:30:00');
    profitLine($c, $this->doll, ['unit_price' => '500.00', 'quantity' => 1, 'line_total' => '500.00', 'cost_price_snapshot' => '300.0000']);

    // Never delivered: shipped, cancelled, courier return. None carries revenue.
    profitLine(profitOrder(OrderStatus::Shipped, null), $this->doll, ['quantity' => 5, 'line_total' => '3500.00']);
    profitLine(profitOrder(OrderStatus::Cancelled, null), $this->doll, ['quantity' => 5, 'line_total' => '3500.00']);
    profitLine(profitOrder(OrderStatus::Returned, null), $this->puzzle, ['quantity' => 1, 'line_total' => '1250.00']);
});

function profitWeek(): ReportPeriod
{
    return ReportPeriod::of('2026-11-02', '2026-11-08');
}

function profitStaff(): User
{
    (new RolesAndPermissionsSeeder)->run();

    return User::factory()->withRole(Role::Accountant)->create()->refresh();
}

it('matches a hand computation by product, netting returns and refunds once', function (): void {
    $report = app(Profitability::class)->byProduct(profitWeek());
    $rows = collect($report['rows'])->keyBy('name');

    $puzzle = $rows['Wooden Puzzle'];
    // Units: (2 − 0) + (3 − 1) = 4. Revenue 2,400 + 3,600 = 6,000, less 300 (goodwill) and
    // max(1,200 returned value, 1,200 refund) = 1,500 → 4,500. COGS 2 × 800 + 2 × 810.50 = 3,221.
    expect($puzzle['units'])->toBe(4)
        ->and($puzzle['units_sold'])->toBe(5)
        ->and($puzzle['units_returned'])->toBe(1)
        ->and($puzzle['orders'])->toBe(2)
        ->and($puzzle['gross_revenue']->toDecimal())->toBe('6000.00')
        ->and($puzzle['reversals']->toDecimal())->toBe('1500.00')
        ->and($puzzle['net_revenue']->toDecimal())->toBe('4500.00')
        ->and($puzzle['cogs']->toDecimal())->toBe('3221.00')
        ->and($puzzle['gross_profit']->toDecimal())->toBe('1279.00')
        ->and($puzzle['margin_percent'])->toBe('28.42')
        ->and($puzzle['category_name'])->toBe('Puzzles');

    $doll = $rows['Rag Doll'];
    expect($doll['net_revenue']->toDecimal())->toBe('700.00')
        ->and($doll['cogs']->toDecimal())->toBe('350.00')
        ->and($doll['margin_percent'])->toBe('50.00');

    expect($report['totals']['net_revenue']->toDecimal())->toBe('5200.00')
        ->and($report['totals']['cogs']->toDecimal())->toBe('3571.00')
        ->and($report['totals']['gross_profit']->toDecimal())->toBe('1629.00')
        ->and($report['totals']['margin_percent'])->toBe('31.33')
        ->and($report['totals']['orders'])->toBe(3)
        // Sorted by gross profit, highest first.
        ->and(array_column($report['rows'], 'name'))->toBe(['Wooden Puzzle', 'Rag Doll'])
        // Read-only: order financials still equal the ledger (§7.2).
        ->and(app(FinanceInvariant::class)->drift())->toBe([]);
});

it('uses the Dhaka date of delivery: 18:30 UTC on 8 Nov is in 9 Nov', function (): void {
    $nextDay = app(Profitability::class)->byProduct(ReportPeriod::of('2026-11-09', '2026-11-09'));

    expect($nextDay['rows'])->toHaveCount(1)
        ->and($nextDay['rows'][0]['name'])->toBe('Rag Doll')
        ->and($nextDay['totals']['net_revenue']->toDecimal())->toBe('500.00');
});

it('groups by primary category and filters a category subtree', function (): void {
    $byCategory = collect(app(Profitability::class)->byCategory(profitWeek())['rows'])->keyBy('name');

    expect($byCategory['Puzzles']['gross_profit']->toDecimal())->toBe('1279.00')
        ->and($byCategory['Puzzles']['products'])->toBe(1)
        ->and($byCategory['Puzzles']['parent_id'])->toBe($this->toys->id)
        ->and($byCategory['Dolls']['gross_profit']->toDecimal())->toBe('350.00');

    $underToys = app(Profitability::class)->byProduct(profitWeek(), ['category_id' => $this->toys->id]);
    expect(array_column($underToys['rows'], 'name'))->toBe(['Wooden Puzzle']);
});

it('breaks profit down by recommendation source (rule 25d)', function (): void {
    $bySource = collect(app(Profitability::class)->bySource(profitWeek())['rows'])->keyBy('source');

    // B's line: 3,600 − 1,200 = 2,400; COGS 2 × 810.50 = 1,621.
    expect($bySource['pdp_cross_sell']['net_revenue']->toDecimal())->toBe('2400.00')
        ->and($bySource['pdp_cross_sell']['gross_profit']->toDecimal())->toBe('779.00')
        ->and($bySource['none']['net_revenue']->toDecimal())->toBe('2800.00')
        ->and($bySource['none']['gross_profit']->toDecimal())->toBe('850.00');
});

it('serves profitability and its CSV over the API with an allowlisted sort', function (): void {
    $user = profitStaff();

    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/profitability?by=product&filter[from]=2026-11-02&filter[to]=2026-11-08&sort=name')
        ->assertOk()
        ->assertJsonPath('by', 'product')
        ->assertJsonPath('data.0.name', 'Rag Doll')
        ->assertJsonPath('data.1.net_revenue', '4500.00')
        ->assertJsonPath('totals.gross_profit', '1629.00');

    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/profitability?filter[from]=2026-11-02&filter[to]=2026-11-08&filter[source]=none')
        ->assertOk()
        ->assertJsonPath('totals.net_revenue', '2800.00');

    $csv = $this->actingAs($user)->get('/api/v1/admin/finance/reports/profitability/export?by=category&filter[from]=2026-11-02&filter[to]=2026-11-08');
    $csv->assertOk();
    $body = $csv->streamedContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->toContain('Puzzles')
        ->and($body)->toContain('1279.00');

    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/profitability?sort=cost_price')->assertStatus(400);
    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/profitability?by=brand')->assertStatus(400);
    $this->actingAs($user)->getJson('/api/v1/admin/finance/reports/profitability?filter[source]=x%20y')->assertStatus(400);
});
