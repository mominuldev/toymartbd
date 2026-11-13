<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\ReceivePurchasedStock;
use App\Domain\Inventory\DTOs\StockMovementLine;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Reports\InventoryValuationReport;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Inventory\Services\StockLedgerReport;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Xlsx;

/*
 * Inventory valuation (P5): on_hand × weighted-average cost per variant × location now,
 * and as at a past Dhaka date replayed from the movement ledger; totals by location and
 * category. Read-only — the §7.1 invariant still holds afterwards.
 */

beforeEach(function (): void {
    $this->dhaka = StockLocation::defaultId();
    $this->ctg = StockLocation::query()->create(['code' => 'ctg', 'name' => 'Chattogram · Agrabad', 'name_bn' => 'চট্টগ্রাম', 'is_active' => true])->id;

    $this->puzzles = Category::factory()->create(['name' => 'Puzzles']);
    $this->dolls = Category::factory()->create(['name' => 'Dolls']);
    $puzzle = Product::factory()->create(['name' => 'Wooden Puzzle', 'primary_category_id' => $this->puzzles->id]);
    $doll = Product::factory()->create(['name' => 'Rag Doll', 'primary_category_id' => $this->dolls->id]);
    $this->puzzleVariant = $puzzle->variants()->firstOrFail();
    $this->puzzleVariant->update(['cost_price' => '310.00']);
    $this->dollVariant = $doll->variants()->firstOrFail();
    $this->dollVariant->update(['cost_price' => '99.50']);

    // 1 Nov (Dhaka): 10 puzzles opened at 310; 3 dolls at Chattogram with no recorded cost.
    $this->travelTo(now()->setDateTime(2026, 11, 1, 4, 0));
    DB::transaction(fn () => app(StockLedger::class)->record([new StockMovementLine($this->puzzleVariant->id, StockMovementType::OpeningBalance, 10, unitCost: '310.0000')], $this->dhaka));
    DB::transaction(fn () => app(StockLedger::class)->record([new StockMovementLine($this->dollVariant->id, StockMovementType::OpeningBalance, 3)], $this->ctg));

    // 5 Nov: receive 10 more at 400 → avg (10 × 310 + 10 × 400) / 20 = 355.
    $this->travelTo(now()->setDateTime(2026, 11, 5, 4, 0));
    app(ReceivePurchasedStock::class)->handle($this->dhaka, [['variant_id' => $this->puzzleVariant->id, 'quantity' => 10, 'landed_unit_cost' => '400.0000']], 'purchase_receipt', 1);

    // 7 Nov: 4 sold.
    $this->travelTo(now()->setDateTime(2026, 11, 7, 4, 0));
    DB::transaction(fn () => app(StockLedger::class)->record([new StockMovementLine($this->puzzleVariant->id, StockMovementType::SaleOut, 4)], $this->dhaka));

    $this->travelTo(now()->setDateTime(2026, 11, 12, 6, 0));
});

it('values stock now at on_hand × weighted-average cost, with location and category totals', function (): void {
    $report = app(InventoryValuationReport::class)->report();
    $rows = collect($report['rows'])->keyBy('sku');

    $puzzle = $rows[$this->puzzleVariant->sku];
    expect($puzzle['on_hand'])->toBe(16)
        ->and($puzzle['unit_cost'])->toBe('355.0000')
        ->and($puzzle['value'])->toBe('5680.00')
        ->and(bccomp((string) StockLevel::query()->where('variant_id', $this->puzzleVariant->id)->value('avg_cost'), '355', 4))->toBe(0);

    // No receipt at Chattogram: the cost price is the basis.
    expect($rows[$this->dollVariant->sku]['value'])->toBe('298.50')
        ->and($report['totals'])->toBe(['on_hand' => 19, 'value' => '5978.50', 'rows' => 2, 'estimated_rows' => 0])
        ->and(collect($report['by_location'])->pluck('value', 'location_id')->all())->toBe([$this->dhaka => '5680.00', $this->ctg => '298.50'])
        ->and(collect($report['by_category'])->pluck('value', 'name')->all())->toBe(['Puzzles' => '5680.00', 'Dolls' => '298.50']);

    // Read-only: the movement ledger still sums to the projection (§7.1).
    expect(app(StockLedgerReport::class)->reconciliation([])['balanced'])->toBeTrue();
});

it('replays units and weighted-average cost from the movement ledger as at a past Dhaka date', function (): void {
    $report = app(InventoryValuationReport::class);

    // End of 4 Nov: only the opening balance — 10 × 310.
    $before = collect($report->report([], '2026-11-04')['rows'])->keyBy('sku');
    expect($before[$this->puzzleVariant->sku]['on_hand'])->toBe(10)
        ->and($before[$this->puzzleVariant->sku]['value'])->toBe('3100.00')
        ->and($before[$this->puzzleVariant->sku]['estimated'])->toBeFalse()
        // The dolls' movement carried no cost: valued at today's basis and flagged.
        ->and($before[$this->dollVariant->sku]['estimated'])->toBeTrue();

    // End of 6 Nov: after the receipt, before the sale — 20 × 355.
    $after = collect($report->report([], '2026-11-06')['rows'])->keyBy('sku');
    expect($after[$this->puzzleVariant->sku]['on_hand'])->toBe(20)
        ->and($after[$this->puzzleVariant->sku]['value'])->toBe('7100.00');

    // As at today equals the live projection.
    expect($report->report([], '2026-11-12')['totals']['value'])->toBe($report->report()['totals']['value']);

    // Before anything existed.
    expect($report->report([], '2026-10-31')['rows'])->toBe([]);
});

it('filters by location and category and serves the CSV', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::Accountant)->create()->refresh();

    $this->actingAs($user)->getJson('/api/v1/admin/reports/inventory-valuation?filter[location_id]='.$this->ctg)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('totals.value', '298.50');

    $this->actingAs($user)->getJson('/api/v1/admin/reports/inventory-valuation?filter[category_id]='.$this->puzzles->id.'&filter[as_at]=2026-11-06')
        ->assertOk()
        ->assertJsonPath('as_at', '2026-11-06')
        ->assertJsonPath('data.0.value', '7100.00');

    $csv = $this->actingAs($user)->get('/api/v1/admin/reports/inventory-valuation/export');
    $csv->assertOk();
    $body = $csv->streamedContent();
    expect(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->toContain('5978.50')
        ->and($body)->toContain('খেলনা'); // Bangla names survive (UTF-8 + BOM)

    $this->actingAs($user)->getJson('/api/v1/admin/reports/inventory-valuation?filter[as_at]=2099-01-01')->assertStatus(400);
    $this->actingAs($user)->getJson('/api/v1/admin/reports/inventory-valuation?sort=-margin')->assertStatus(400);
    $this->actingAs($user)->getJson('/api/v1/admin/reports/inventory-valuation?filter[location_id]=999')->assertStatus(400);
});

it('keeps the valuation CSV byte-for-byte as before XLSX', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::Accountant)->create()->refresh();
    $this->puzzleVariant->product->update(['name_bn' => 'কাঠের পাজল']);
    $this->dollVariant->product->update(['name_bn' => 'কাপড়ের পুতুল']);

    $response = $this->actingAs($user)->get('/api/v1/admin/reports/inventory-valuation/export');
    $response->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=inventory-valuation-2026-11-12.csv');
    expect($response->streamedContent())->toBe(
        "\u{FEFF}sku,name,name_bn,variant_name,category_name,location_code,location_name,on_hand,unit_cost,value,estimated\n"
        ."{$this->puzzleVariant->sku},\"Wooden Puzzle\",\"কাঠের পাজল\",,Puzzles,main,\"Main warehouse\",16,355.0000,5680.00,0\n"
        ."{$this->dollVariant->sku},\"Rag Doll\",\"কাপড়ের পুতুল\",,Dolls,ctg,\"Chattogram · Agrabad\",3,99.5000,298.50,0\n"
        .",Total,,,,,,19,,5978.50,\n",
    );
});

it('exports the valuation as XLSX equal to the JSON, with Bangla labels, guarded text and text SKUs', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->withRole(Role::InventoryStaff)->create()->refresh();
    $user->givePermissionTo('finance.export');
    $this->puzzleVariant->product->update(['name' => '@SUM(A1:A9)', 'name_bn' => 'কাঠের পাজল']);
    $this->puzzleVariant->update(['sku' => '00042']);

    $json = $this->actingAs($user)->getJson('/api/v1/admin/reports/inventory-valuation')->assertOk()->json();
    $response = $this->actingAs($user)->get('/api/v1/admin/reports/inventory-valuation/export?format=xlsx&locale=bn');
    $response->assertHeader('Content-Disposition', 'attachment; filename="inventory-valuation-2026-11-12.xlsx"');
    $sheet = Xlsx::single($response);

    expect($sheet['name'])->toBe('ইনভেন্টরি মূল্যায়ন')
        ->and($sheet['header'])->toBe(['sku', 'name', 'name_bn', 'variant_name', 'category_name', 'location_code', 'location_name', 'on_hand', 'unit_cost', 'value', 'estimated'])
        ->and($sheet['rows'])->toHaveCount(count($json['data']) + 1);
    foreach ($json['data'] as $i => $row) {
        expect($sheet['rows'][$i]['sku'])->toBe($row['sku'])
            ->and($sheet['rows'][$i]['on_hand'])->toBe($row['on_hand'])
            ->and($sheet['rows'][$i]['unit_cost'])->toEqual(Xlsx::number($row['unit_cost']))
            ->and($sheet['rows'][$i]['value'])->toEqual(Xlsx::number($row['value']))
            ->and($sheet['rows'][$i]['estimated'])->toBe($row['estimated'] ? 1 : 0);
    }
    $puzzle = collect($sheet['rows'])->firstWhere('sku', '00042');
    $total = $sheet['rows'][count($json['data'])];
    expect($puzzle['name'])->toBe("'@SUM(A1:A9)")
        ->and($puzzle['name_bn'])->toBe('কাঠের পাজল')
        ->and($total['name'])->toBe((string) __('reports.valuation.total', [], 'bn'))
        ->and($total['on_hand'])->toBe($json['totals']['on_hand'])
        ->and($total['value'])->toEqual(Xlsx::number($json['totals']['value']));
});
