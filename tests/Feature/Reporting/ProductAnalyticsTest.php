<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\RoleMatrix;

/*
 * Product analytics (P10, Analytics › Products): best sellers over delivered lines, return
 * rate, days of stock left from placed pace and available stock, and the stock side cards.
 * Hand-computed against a seeded November.
 */

/** One order with one line: [product, qty, line total, cost snapshot, qty returned]. */
function productAnalyticsOrder(Customer $customer, string $placedUtc, OrderStatus $status, Product $product, int $qty, string $lineTotal, string $cost, int $returned = 0, ?string $deliveredUtc = null): Order
{
    $order = Order::factory()->status($status)->create([
        'customer_id' => $customer->id,
        'placed_at' => CarbonImmutable::parse($placedUtc, 'UTC'),
        'delivered_at' => $deliveredUtc === null ? null : CarbonImmutable::parse($deliveredUtc, 'UTC'),
        'subtotal' => $lineTotal, 'shipping_charge' => '0.00', 'grand_total' => $lineTotal, 'due_total' => $lineTotal,
    ]);
    $variant = $product->variants()->firstOrFail();
    OrderItem::factory()->create([
        'order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $product->id,
        'product_name' => $product->name, 'sku' => $variant->sku,
        'unit_price' => bcdiv($lineTotal, (string) $qty, 2), 'quantity' => $qty, 'line_total' => $lineTotal,
        'cost_price_snapshot' => $cost, 'quantity_shipped' => $status === OrderStatus::Processing ? 0 : $qty, 'quantity_returned' => $returned,
    ]);

    return $order;
}

function productAnalyticsStock(Product $product, int $onHand, int $reserved = 0, string $costPrice = '0.00'): void
{
    $variant = $product->variants()->firstOrFail();
    $variant->forceFill(['cost_price' => $costPrice])->save();
    DB::table('stock_levels')->insert([
        'variant_id' => $variant->id, 'location_id' => StockLocation::defaultId(),
        'on_hand' => $onHand, 'reserved' => $reserved, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-05 06:00:00', 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);

    $toys = Category::factory()->create(['name' => 'Puzzles', 'name_bn' => 'পাজল']);
    $this->puzzle = Product::factory()->create(['name' => 'Wooden Puzzle', 'name_bn' => 'কাঠের পাজল', 'primary_category_id' => $toys->id]);
    $this->doll = Product::factory()->create(['name' => 'Rag Doll', 'primary_category_id' => $toys->id]);
    $this->car = Product::factory()->create(['name' => 'Race Car', 'primary_category_id' => $toys->id]);
    $this->kite = Product::factory()->create(['name' => 'Paper Kite', 'primary_category_id' => $toys->id]);
    $archived = Product::factory()->create(['name' => 'Old Top', 'status' => ProductStatus::Archived]);

    productAnalyticsStock($this->puzzle, 12, 2, '600.00');   // 10 available
    productAnalyticsStock($this->doll, 1, 0, '300.00');
    productAnalyticsStock($this->car, 1, 0, '500.00');
    productAnalyticsStock($this->kite, 5, 0, '200.00');       // 1,000.00 at cost
    productAnalyticsStock($archived, 9, 0, '100.00');         // archived: in no side card

    $c = Customer::factory()->create();

    // October (the comparison range for November): 2 puzzles delivered.
    productAnalyticsOrder($c, '2026-10-10 04:00:00', OrderStatus::Delivered, $this->puzzle, 2, '2000.00', '600.0000', 0, '2026-10-12 06:00:00');

    // November.
    // Puzzle: 6 delivered at 6,000, cost 600 → profit 2,400 (40%); plus 4 shipped, not delivered.
    productAnalyticsOrder($c, '2026-11-05 04:00:00', OrderStatus::Delivered, $this->puzzle, 6, '6000.00', '600.0000', 0, '2026-11-07 06:00:00');
    productAnalyticsOrder($c, '2026-11-20 04:00:00', OrderStatus::Shipped, $this->puzzle, 4, '4000.00', '600.0000');
    // Doll: 3 delivered at 1,500, 1 came back → net 1,000, cogs 2 × 300 → profit 400.
    productAnalyticsOrder($c, '2026-11-08 04:00:00', OrderStatus::PartiallyReturned, $this->doll, 3, '1500.00', '300.0000', 1, '2026-11-10 06:00:00');
    // Car: 1 delivered at 800, cost 500 → profit 300; plus 5 placed on 25 Nov, still processing.
    productAnalyticsOrder($c, '2026-11-09 04:00:00', OrderStatus::Delivered, $this->car, 1, '800.00', '500.0000', 0, '2026-11-11 06:00:00');
    productAnalyticsOrder($c, '2026-11-25 04:00:00', OrderStatus::Processing, $this->car, 5, '4000.00', '500.0000');
    // Kite: placed and cancelled — not a sale.
    productAnalyticsOrder($c, '2026-11-12 04:00:00', OrderStatus::Cancelled, $this->kite, 2, '600.00', '200.0000');

    $this->manager = User::factory()->withRole(Role::Manager)->create()->refresh();
    $this->viewer = User::factory()->withRole(Role::Viewer)->create()->refresh();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function productAnalyticsNovember(): string
{
    return '/api/v1/admin/analytics/products?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-30';
}

it('reports best sellers, returns, days of stock and the side cards for a seeded month', function (): void {
    $r = $this->actingAs($this->manager)->getJson(productAnalyticsNovember())->assertOk();

    $r->assertJsonPath('range', ['preset' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-30', 'days' => 30])
        ->assertJsonPath('profit_visible', true)
        ->assertJsonPath('summary.products_sold', 3)
        ->assertJsonPath('summary.products_on_sale', 4)
        ->assertJsonPath('summary.units_delivered', 10)
        ->assertJsonPath('summary.units_delivered_previous', 2)
        ->assertJsonPath('summary.units_delivered_change', 8)
        ->assertJsonPath('summary.units_delta', '400.00')
        ->assertJsonPath('summary.net_revenue', '7800.00')
        ->assertJsonPath('summary.gross_profit', '3100.00')
        ->assertJsonPath('summary.margin_percent', '39.74')
        ->assertJsonPath('summary.units_returned', 1)
        ->assertJsonPath('summary.return_rate', '10.00')
        ->assertJsonPath('summary.running_out_count', 2)
        ->assertJsonPath('summary.running_out_urgent_count', 1)
        ->assertJsonPath('summary.not_selling_count', 1);

    // Best sellers by revenue: puzzle, doll, car.
    $r->assertJsonPath('products.0.name', 'Wooden Puzzle')
        ->assertJsonPath('products.0.name_bn', 'কাঠের পাজল')
        ->assertJsonPath('products.0.category_name', 'Puzzles')
        ->assertJsonPath('products.0.units_delivered', 6)
        ->assertJsonPath('products.0.net_revenue', '6000.00')
        ->assertJsonPath('products.0.margin_percent', '40.00')
        ->assertJsonPath('products.0.stock_left', 10)
        // Pace 6 + 4 placed = 10 over 30 days; 10 available → 30 days.
        ->assertJsonPath('products.0.days_of_stock', 30)
        ->assertJsonPath('products.1.name', 'Rag Doll')
        ->assertJsonPath('products.1.net_revenue', '1000.00')
        ->assertJsonPath('products.1.return_rate', '33.33')
        ->assertJsonPath('products.2.name', 'Race Car');

    $r->assertJsonPath('highlights.best_seller.name', 'Wooden Puzzle')
        ->assertJsonPath('highlights.most_returned.name', 'Rag Doll')
        ->assertJsonPath('highlights.most_returned.units_returned', 1)
        ->assertJsonPath('highlights.most_returned.units_delivered', 3);

    // Car: 1 left, 6 placed in 30 days → 5 days. Doll: 1 left, 3 placed → 10 days.
    $r->assertJsonPath('running_out.0.name', 'Race Car')
        ->assertJsonPath('running_out.0.stock_left', 1)
        ->assertJsonPath('running_out.0.days_of_stock', 5)
        ->assertJsonPath('running_out.1.name', 'Rag Doll')
        ->assertJsonPath('running_out.1.days_of_stock', 10)
        ->assertJsonCount(2, 'running_out');

    // Kite: stock 5, only a cancelled order → not selling, 5 × 200 at cost.
    $r->assertJsonPath('not_selling.0.name', 'Paper Kite')
        ->assertJsonPath('not_selling.0.stock_left', 5)
        ->assertJsonPath('not_selling.0.days_of_stock', null)
        ->assertJsonPath('not_selling.0.stock_value', '1000.00')
        ->assertJsonCount(1, 'not_selling');
});

it('orders the best sellers by units, margin or returns on request', function (): void {
    $this->actingAs($this->manager)->getJson(productAnalyticsNovember().'&sort=returns')->assertOk()
        ->assertJsonPath('sort', 'returns')
        ->assertJsonPath('products.0.name', 'Rag Doll');
    $this->actingAs($this->manager)->getJson(productAnalyticsNovember().'&sort=units')->assertOk()
        ->assertJsonPath('products.0.name', 'Wooden Puzzle')
        ->assertJsonPath('products.1.name', 'Rag Doll');
    // Margin: puzzle 40%, doll 40%, car 37.5% → ties broken by revenue.
    $this->actingAs($this->manager)->getJson(productAnalyticsNovember().'&sort=margin')->assertOk()
        ->assertJsonPath('products.2.name', 'Race Car');
});

it('honours a custom range: delivered figures and the pace both follow it', function (): void {
    // 20–30 Nov (11 days): nothing delivered; puzzle 4 and car 5 placed.
    $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/products?filter[from]=2026-11-20&filter[to]=2026-11-30')->assertOk()
        ->assertJsonPath('range', ['preset' => 'custom', 'from' => '2026-11-20', 'to' => '2026-11-30', 'days' => 11])
        ->assertJsonPath('comparison.from', '2026-11-09')
        ->assertJsonPath('summary.products_sold', 0)
        ->assertJsonPath('summary.units_delivered', 0)
        ->assertJsonPath('summary.return_rate', null)
        ->assertJsonPath('highlights.best_seller', null)
        // Car: 1 × 11 ÷ 5 = 2 days. Puzzle: 10 × 11 ÷ 4 = 27 → not running out.
        ->assertJsonPath('running_out.0.name', 'Race Car')
        ->assertJsonPath('running_out.0.days_of_stock', 2)
        ->assertJsonCount(1, 'running_out')
        // Doll had no order in these days: now in "not selling".
        ->assertJsonPath('not_selling.0.name', 'Paper Kite');
});

it('defaults to the last 30 Dhaka days', function (): void {
    $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/products')->assertOk()
        ->assertJsonPath('range', ['preset' => '30d', 'from' => '2026-11-06', 'to' => '2026-12-05', 'days' => 30]);
});

it('hides cost, profit, margin and stock value from a role without cost permission', function (): void {
    $this->actingAs($this->viewer)->getJson(productAnalyticsNovember().'&sort=margin')->assertOk()
        ->assertJsonPath('profit_visible', false)
        ->assertJsonPath('summary.gross_profit', null)
        ->assertJsonPath('summary.margin_percent', null)
        ->assertJsonPath('summary.net_revenue', '7800.00')
        ->assertJsonPath('products.0.name', 'Wooden Puzzle')
        ->assertJsonPath('products.0.cogs', null)
        ->assertJsonPath('products.0.gross_profit', null)
        ->assertJsonPath('products.0.margin_percent', null)
        ->assertJsonPath('highlights.best_seller.margin_percent', null)
        ->assertJsonPath('not_selling.0.stock_value', null)
        ->assertJsonPath('products.0.return_rate', '0.00');
});

it('guards GET /analytics/products for every role', function (): void {
    RoleMatrix::assert($this, Permission::ReportsView, fn () => $this->getJson('/api/v1/admin/analytics/products'));
});

it('refuses a guest with 401', function (): void {
    $this->getJson('/api/v1/admin/analytics/products')->assertUnauthorized();
});

it('rejects an unlisted parameter or filter, an unknown preset or sort and a half range with 400', function (string $query): void {
    $this->actingAs($this->manager)->getJson('/api/v1/admin/analytics/products?'.$query)
        ->assertStatus(400)
        ->assertJsonPath('type', fn (string $t): bool => str_ends_with($t, 'bad-request'));
})->with([
    'unlisted filter' => 'filter[category_id]=1',
    'unlisted parameter' => 'group=day',
    'preset' => 'range=90d',
    'sort' => 'sort=name',
    'half' => 'range=custom&filter[from]=2026-11-01',
    'future' => 'filter[from]=2026-12-01&filter[to]=2027-01-01',
]);
