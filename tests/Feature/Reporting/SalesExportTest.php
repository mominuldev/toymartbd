<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Finance\Actions\RecognizeOrderRevenue;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Reporting\Exports\Sheet;
use App\Domain\Reporting\Exports\Spreadsheet;
use App\Models\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use OpenSpout\Reader\XLSX\Reader;

/*
 * Sales analytics export (P8): CSV and XLSX, streamed, formula-injection guarded like the
 * product export, filenames carrying the Dhaka dates covered and the Dhaka export day.
 */

beforeEach(function (): void {
    // 23:30 UTC on 12 Nov is already 13 Nov in Dhaka.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-12 23:30:00', 'UTC'));
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withRole(Role::Manager)->create()->refresh();

    $category = Category::factory()->create(['name' => '@SUM(A1:A9)', 'name_bn' => 'পাজল']);
    $product = Product::factory()->create(['name' => '=HYPERLINK("http://evil.example")', 'primary_category_id' => $category->id]);
    $variant = $product->variants()->firstOrFail();
    $order = Order::factory()->status(OrderStatus::Delivered)->create([
        'customer_id' => Customer::factory(),
        'placed_at' => CarbonImmutable::parse('2026-11-10 04:00:00', 'UTC'),
        'delivered_at' => CarbonImmutable::parse('2026-11-11 04:00:00', 'UTC'),
        'coupon_code' => '+CMD|calc', 'coupon_discount' => '0.00',
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $product->id, 'sku' => $variant->sku, 'quantity_shipped' => 1]);
    app(RecognizeOrderRevenue::class)->handle($order->id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('streams a guarded CSV with Dhaka-dated filename', function (): void {
    $response = $this->actingAs($this->manager)->get('/api/v1/admin/analytics/sales/export?range=custom&filter[from]=2026-11-01&filter[to]=2026-11-13&format=csv');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="sales-analytics-2026-11-01-to-2026-11-13-exported-2026-11-13.csv"');
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\u{FEFF}")
        ->toContain("'@SUM(A1:A9)")
        ->toContain("'+CMD|CALC")
        ->not->toContain(',@SUM(')
        ->not->toContain(',+CMD')
        // Money stays a plain, unguarded number.
        ->toContain('net_revenue')
        ->toContain('1320.00');
});

it('streams an XLSX with one sheet per section, Bangla labels and numeric cells', function (): void {
    $response = $this->actingAs($this->manager)->get('/api/v1/admin/analytics/sales/export?range=this_month&format=xlsx&locale=bn');
    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="sales-analytics-2026-11-01-to-2026-11-13-exported-2026-11-13.xlsx"');

    $path = tempnam(sys_get_temp_dir(), 'xlsx-').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[$sheet->getName()] = $rows;
    }
    $reader->close();
    @unlink($path);

    expect(array_keys($sheets))->toContain('সারসংক্ষেপ', 'ক্যাটাগরি', 'কুপন')
        ->and($sheets['ক্যাটাগরি'][1][1])->toBe("'@SUM(A1:A9)")
        ->and($sheets['কুপন'][1][0])->toBe("'+CMD|CALC");

    $summary = array_column($sheets['সারসংক্ষেপ'], 2, 0);
    expect($summary['net_revenue'])->toEqual(1320)
        ->and($summary['placed_orders'])->toBe(1);
});

it('guards every text cell that a spreadsheet would evaluate, and keeps numbers numbers', function (string $in, string $out): void {
    expect(Spreadsheet::safe($in))->toBe($out);
})->with([
    ['=1+1', "'=1+1"],
    ['+1', "'+1"],
    ['-cmd', "'-cmd"],
    ['@SUM(1)', "'@SUM(1)"],
    ["\tlead", "'\tlead"],
    ["\rlead", "'\rlead"],
    ['-1280.00', '-1280.00'],
    ['42', '42'],
    ['Puzzle = fun', 'Puzzle = fun'],
    ['পাজল', 'পাজল'],
]);

it('writes multi-section CSV with a title row per section', function (): void {
    $csv = Spreadsheet::contents('csv', [
        new Sheet('One', ['a'], [['1']]),
        new Sheet('Two', ['b'], [['=x']]),
    ]);

    expect(str_replace("\u{FEFF}", '', $csv))->toBe("One\na\n1\n\nTwo\nb\n'=x\n");
});

it('refuses an unknown format with 400 and a role without reports.export with 403', function (): void {
    $this->actingAs($this->manager)->get('/api/v1/admin/analytics/sales/export?format=pdf', ['Accept' => 'application/json'])->assertStatus(400);

    $viewer = User::factory()->withRole(Role::Viewer)->create()->refresh();
    $this->actingAs($viewer)->get('/api/v1/admin/analytics/sales/export', ['Accept' => 'application/json'])->assertForbidden();
});
