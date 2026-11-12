<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Admin inventory API: /api/v1/admin/inventory/* — overview, ledger, reconciliation,
 * adjustments.
 */

const INV_API = '/api/v1/admin/inventory';

function invStock(int $variantId, int $quantity, StockMovementType $type = StockMovementType::OpeningBalance, ?int $locationId = null): void
{
    DB::transaction(fn () => app(StockLedger::class)->record([
        new App\Domain\Inventory\DTOs\StockMovementLine($variantId, $type, $quantity),
    ], $locationId));
}

function invVariant(string $name, string $cost = '100.00', int $reorderPoint = 0): ProductVariant
{
    $product = Product::factory()->withoutVariants()->create(['name' => $name, 'status' => ProductStatus::Active]);
    $variant = ProductVariant::factory()->for($product)->create(['cost_price' => $cost]);
    if ($reorderPoint > 0) {
        StockLevel::query()->create(['variant_id' => $variant->id, 'location_id' => StockLocation::defaultId(), 'reorder_point' => $reorderPoint]);
    }

    return $variant;
}

/** @param  array<string, mixed>  $body */
function invAdjust(array $body, ?string $key = null): Illuminate\Testing\TestResponse
{
    return test()->postJson(INV_API.'/adjustments', $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');

    $this->puzzle = invVariant('Wooden Alphabet Puzzle', '310.00', reorderPoint: 12);
    $this->doll = invVariant('Fashion Doll', '1480.00');
    $this->empty = invVariant('Kids Tricycle', '3250.00');
    invStock($this->puzzle->id, 8);
    invStock($this->doll->id, 5);
    app(ReserveStock::class)->handle([$this->puzzle->id => 2]);
});

it('lists one row per sellable variant with on hand, reserved, available, state and value', function (): void {
    $rows = collect($this->getJson(INV_API.'/stock')->assertOk()->json('data'))->keyBy('variant_id');

    expect($rows)->toHaveCount(3)
        ->and($rows[$this->puzzle->id])->toMatchArray([
            'product_name' => 'Wooden Alphabet Puzzle',
            'on_hand' => 8, 'reserved' => 2, 'available' => 6, 'reorder_point' => 12,
            'reorder_quantity' => 0, 'state' => 'low', 'unit_cost' => '310.0000', 'value' => '2480.00', 'elsewhere' => [], 'transfer_from' => null,
        ])
        ->and($rows[$this->doll->id]['state'])->toBeNull()
        ->and($rows[$this->empty->id])->toMatchArray(['on_hand' => 0, 'available' => 0, 'state' => 'out']);
});

it('filters by state and search', function (string $query, Closure $expected): void {
    $ids = collect($this->getJson(INV_API.'/stock?'.$query)->assertOk()->json('data'))->pluck('variant_id')->all();

    expect($ids)->toBe($expected->call($this));
})->with([
    'low' => ['filter[state]=low', fn () => [$this->puzzle->id]],
    'out' => ['filter[state]=out', fn () => [$this->empty->id]],
    'search' => ['filter[search]=doll', fn () => [$this->doll->id]],
]);

it('lists idle stock: on hand but no movement for 90 days', function (): void {
    Carbon::setTestNow(now()->addDays(91));
    invStock($this->doll->id, 1, StockMovementType::AdjustmentIn);

    $ids = collect($this->getJson(INV_API.'/stock?filter[state]=idle')->assertOk()->json('data'))->pluck('variant_id')->all();

    expect($ids)->toBe([$this->puzzle->id]);
    Carbon::setTestNow();
});

it('keeps an archived product on the list while it still holds stock', function (): void {
    $this->doll->product->update(['status' => ProductStatus::Archived]);
    $this->empty->product->update(['status' => ProductStatus::Archived]);

    $ids = collect($this->getJson(INV_API.'/stock')->json('data'))->pluck('variant_id')->sort()->values()->all();

    expect($ids)->toBe(collect([$this->puzzle->id, $this->doll->id])->sort()->values()->all());
});

it('cursor-paginates the stock list', function (): void {
    foreach (range(1, 26) as $i) {
        invVariant("Bulk {$i}");
    }

    $first = $this->getJson(INV_API.'/stock')->assertOk();
    $next = $first->json('meta.next_cursor');
    $second = $this->getJson(INV_API.'/stock?cursor='.urlencode((string) $next))->assertOk();

    expect($first->json('data'))->toHaveCount(25)
        ->and($second->json('data'))->toHaveCount(4)
        ->and(array_intersect(array_column($first->json('data'), 'variant_id'), array_column($second->json('data'), 'variant_id')))->toBe([]);
});

it('shows stock held at other locations, every other branch named, zeros included', function (): void {
    $ctg = StockLocation::query()->create(['code' => 'ctg', 'name' => 'Chattogram · Agrabad', 'description' => 'Retail branch · POS + pickup', 'is_active' => true]);
    $syl = StockLocation::query()->create(['code' => 'syl', 'name' => 'Sylhet pickup point', 'is_active' => true]);
    invStock($this->puzzle->id, 4, locationId: $ctg->id);

    $rows = collect($this->getJson(INV_API.'/stock')->json('data'))->keyBy('variant_id');
    $summary = $this->getJson(INV_API.'/summary')->assertOk();

    expect($rows[$this->puzzle->id]['elsewhere'])->toBe([
        ['location_id' => $ctg->id, 'code' => 'ctg', 'on_hand' => 4, 'available' => 4],
        ['location_id' => $syl->id, 'code' => 'syl', 'on_hand' => 0, 'available' => 0],
    ])
        // Low here, 4 available in Chattogram: that is where a transfer would come from.
        ->and($rows[$this->puzzle->id]['transfer_from'])->toBe(['location_id' => $ctg->id, 'code' => 'ctg', 'available' => 4])
        // Out here and nowhere else either: nothing to transfer.
        ->and($rows[$this->empty->id]['transfer_from'])->toBeNull()
        // Healthy rows never suggest a transfer.
        ->and($rows[$this->doll->id]['transfer_from'])->toBeNull()
        ->and($summary->json('locations'))->toHaveCount(3)
        ->and($summary->json('locations.1'))->toMatchArray(['code' => 'ctg', 'description' => 'Retail branch · POS + pickup', 'on_hand' => 4, 'value' => '1240.00'])
        ->and($summary->json('total_value'))->toBe('11120.00');

    // Seen from Chattogram, Dhaka is the other branch (default first).
    $fromCtg = collect($this->getJson(INV_API.'/stock?filter[location_id]='.$ctg->id)->json('data'))->firstWhere('variant_id', $this->puzzle->id);
    expect(array_column($fromCtg['elsewhere'], 'code'))->toBe(['main', 'syl'])
        ->and($fromCtg['elsewhere'][0])->toMatchArray(['on_hand' => 8, 'available' => 6]);
});

it('lists overstock: reorder quantity set and available above the point plus two reorders', function (): void {
    // Doll: 5 on hand. Point 1 + 2 × 2 = 5 → not over; point 1 + 2 × 1 = 3 → over.
    app(App\Domain\Inventory\Actions\SetReorderLevels::class)->handle(StockLocation::defaultId(), [$this->doll->id => ['point' => 1, 'quantity' => 2]]);
    $ids = fn () => collect($this->getJson(INV_API.'/stock?filter[state]=overstock')->assertOk()->json('data'))->pluck('variant_id')->all();
    expect($ids())->toBe([])
        ->and($this->getJson(INV_API.'/summary')->json('counts.overstock'))->toBe(0);

    app(App\Domain\Inventory\Actions\SetReorderLevels::class)->handle(StockLocation::defaultId(), [$this->doll->id => ['point' => 1, 'quantity' => 1]]);
    $row = collect($this->getJson(INV_API.'/stock')->json('data'))->firstWhere('variant_id', $this->doll->id);

    expect($ids())->toBe([$this->doll->id])
        ->and($row)->toMatchArray(['reorder_point' => 1, 'reorder_quantity' => 1, 'state' => 'overstock'])
        ->and($this->getJson(INV_API.'/summary')->json('counts.overstock'))->toBe(1);
});

it('never calls a level without a reorder quantity overstock', function (): void {
    invStock($this->doll->id, 500, StockMovementType::AdjustmentIn);

    expect($this->getJson(INV_API.'/stock?filter[state]=overstock')->json('data'))->toBe([]);
});

it('counts low stock at the default location for the nav badge, and refreshes after a stock change', function (): void {
    $this->getJson(INV_API.'/low-stock-count')->assertOk()->assertExactJson(['location_id' => StockLocation::defaultId(), 'count' => 1]);

    // The doll (5 on hand) becomes low once its reorder point is 5; the cached count follows.
    app(App\Domain\Inventory\Actions\SetReorderLevels::class)->handle(StockLocation::defaultId(), [$this->doll->id => ['point' => 5, 'quantity' => 10]]);
    $this->getJson(INV_API.'/low-stock-count')->assertOk()->assertJsonPath('count', 2);

    // Found stock takes the puzzle above its point: the stock change drops the cached count.
    invAdjust(['reason' => 'found', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => 20]]])->assertCreated();
    $this->getJson(INV_API.'/low-stock-count')->assertOk()->assertJsonPath('count', 1);
});

it('summarises one location: counts per tab, units, reserved, value at cost', function (): void {
    $this->getJson(INV_API.'/summary')->assertOk()->assertJson([
        'location_id' => StockLocation::defaultId(),
        'counts' => ['all' => 3, 'low' => 1, 'out' => 1, 'overstock' => 0, 'idle' => 0],
        'on_hand' => 13,
        'reserved' => 2,
        // 8 × 310 + 5 × 1480
        'value' => '9880.00',
        'total_value' => '9880.00',
    ]);
});

it('hides every cost and value from staff without catalog.view_cost', function (): void {
    $this->actingAs(User::factory()->withRole(Role::OrderOperator)->create()->refresh(), 'web');

    $row = $this->getJson(INV_API.'/stock')->assertOk()->json('data.0');
    $summary = $this->getJson(INV_API.'/summary')->assertOk()->json();
    $movement = $this->getJson(INV_API.'/movements')->assertOk()->json('data.0');
    $reconciliation = $this->getJson(INV_API.'/movements/reconciliation')->assertOk()->json();

    expect($row)->not->toHaveKeys(['unit_cost', 'value'])
        ->and($summary)->not->toHaveKeys(['value', 'total_value'])
        ->and($summary['locations'][0])->not->toHaveKey('value')
        ->and($movement)->not->toHaveKey('unit_cost')
        ->and($reconciliation)->not->toHaveKey('stock_value');
});

it('refuses unlisted filters, a sort, and bad values with 400', function (string $uri): void {
    $this->getJson($uri)->assertStatus(400)->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with([
    INV_API.'/stock?filter[colour]=red',
    INV_API.'/stock?sort=name',
    INV_API.'/stock?filter[state]=overflowing',
    INV_API.'/stock?filter[location_id]=999',
    INV_API.'/summary?filter[search]=x',
    INV_API.'/movements?filter[type]=sale_out,teleport',
    INV_API.'/movements?filter[from]=2026-13-01',
    INV_API.'/movements?filter[from]=2026-11-10&filter[to]=2026-11-01',
    INV_API.'/movements?filter[variant_id]=abc',
    INV_API.'/movements/reconciliation?filter[type]=sale_out',
]);

it('lists the ledger newest first with type, signed quantity, balance, cost, reference and who', function (): void {
    invAdjust(['reason' => 'damage', 'note' => 'Crushed', 'lines' => [['variant_id' => $this->doll->id, 'quantity' => -2]]])->assertCreated();

    $data = $this->getJson(INV_API.'/movements')->assertOk()->json('data');

    expect($data)->toHaveCount(3)
        ->and($data[0])->toMatchArray([
            'variant_id' => $this->doll->id,
            'product_name' => 'Fashion Doll',
            'type' => 'damage_out',
            'quantity' => -2,
            'balance_after' => 3,
            'unit_cost' => '1480.0000',
            'reference_type' => 'stock_adjustment',
            'note' => 'Crushed',
        ])
        ->and($data[0]['reference_number'])->toMatch('/^ADJ-\d{4}-\d{4}$/')
        ->and($data[0]['user_name'])->toBeString()
        ->and($data[1]['type'])->toBe('opening_balance');
});

it('filters the ledger by variant, type, location and Dhaka date range', function (): void {
    Carbon::setTestNow('2026-11-05 20:00:00'); // 02:00 on 6 Nov in Dhaka
    invStock($this->doll->id, 3, StockMovementType::AdjustmentIn);
    Carbon::setTestNow();

    $ids = fn (string $q) => collect($this->getJson(INV_API.'/movements?'.$q)->assertOk()->json('data'))->pluck('type')->all();

    expect($ids("filter[variant_id]={$this->doll->id}"))->toHaveCount(2)
        ->and($ids('filter[type]=adjustment_in'))->toBe(['adjustment_in'])
        ->and($ids('filter[from]=2026-11-06&filter[to]=2026-11-06'))->toBe(['adjustment_in'])
        ->and($ids('filter[to]=2026-11-05'))->toBe([])
        ->and($ids('filter[location_id]='.StockLocation::defaultId()))->toHaveCount(3);
});

it('reconciles the period: opening, in, out, closing, and the ledger matches the levels', function (): void {
    Carbon::setTestNow(now()->addDays(10));
    invAdjust(['reason' => 'found', 'lines' => [['variant_id' => $this->doll->id, 'quantity' => 4]]])->assertCreated();
    invAdjust(['reason' => 'loss', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -1]]])->assertCreated();
    $from = now()->setTimezone('Asia/Dhaka')->toDateString();
    Carbon::setTestNow();

    $this->getJson(INV_API."/movements/reconciliation?filter[from]={$from}")->assertOk()->assertJson([
        'opening' => 13,
        'in' => 4,
        'out' => -1,
        'closing' => 16,
        'variants_checked' => 2,
        'drift_count' => 0,
        'drift_variant_ids' => [],
        'balanced' => true,
        // 7 × 310 + 9 × 1480
        'stock_value' => '15490.00',
    ]);
});

it('reports drift when a level was written outside the ledger, and repairs nothing', function (): void {
    DB::table('stock_levels')->where('variant_id', $this->doll->id)->update(['on_hand' => 9]);

    $this->getJson(INV_API.'/movements/reconciliation')->assertOk()->assertJson([
        'balanced' => false,
        'drift_count' => 1,
        'drift_variant_ids' => [$this->doll->id],
    ]);
    expect(StockLevel::query()->where('variant_id', $this->doll->id)->value('on_hand'))->toBe(9);
});

it('records an adjustment by count and returns the document', function (): void {
    $response = invAdjust(['reason' => 'recount', 'note' => 'Monthly count', 'lines' => [['variant_id' => $this->puzzle->id, 'counted' => 3]]])
        ->assertCreated();

    expect($response->json())->toMatchArray(['reason' => 'recount', 'status' => 'applied', 'note' => 'Monthly count'])
        ->and($response->json('items.0'))->toMatchArray(['variant_id' => $this->puzzle->id, 'quantity_before' => 8, 'quantity_after' => 3, 'change' => -5, 'unit_cost' => '310.0000'])
        ->and(StockLevel::query()->where('variant_id', $this->puzzle->id)->value('on_hand'))->toBe(3);
});

it('replays a retried adjustment instead of moving stock twice', function (): void {
    $body = ['reason' => 'damage', 'lines' => [['variant_id' => $this->doll->id, 'quantity' => -1]]];

    $first = invAdjust($body, 'adj-retry-0001')->assertCreated();
    invAdjust($body, 'adj-retry-0001')->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('id', $first->json('id'));

    expect(StockLevel::query()->where('variant_id', $this->doll->id)->value('on_hand'))->toBe(4)
        ->and(StockMovement::query()->where('type', 'damage_out')->count())->toBe(1);
});

it('requires an Idempotency-Key on an adjustment', function (): void {
    $this->postJson(INV_API.'/adjustments', ['reason' => 'damage', 'lines' => [['variant_id' => $this->doll->id, 'quantity' => -1]]])
        ->assertStatus(400)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/idempotency-key-missing');
});

it('validates an adjustment and names the field', function (array $body, string $field): void {
    invAdjust($body)->assertUnprocessable()->assertJsonFragment(['field' => $field]);
})->with([
    'no reason' => [['lines' => [['variant_id' => 1, 'quantity' => -1]]], 'reason'],
    'unknown reason' => [['reason' => 'theft-ish', 'lines' => [['variant_id' => 1, 'quantity' => -1]]], 'reason'],
    'no lines' => [['reason' => 'damage', 'lines' => []], 'lines'],
    'zero change' => [['reason' => 'damage', 'lines' => [['variant_id' => 1, 'quantity' => 0]]], 'lines.0.quantity'],
    'neither' => [['reason' => 'damage', 'lines' => [['variant_id' => 1]]], 'lines.0.quantity'],
    'both' => [['reason' => 'recount', 'lines' => [['variant_id' => 1, 'quantity' => -1, 'counted' => 3]]], 'lines.0.quantity'],
    'negative count' => [['reason' => 'recount', 'lines' => [['variant_id' => 1, 'counted' => -1]]], 'lines.0.counted'],
    'unknown variant' => [['reason' => 'damage', 'lines' => [['variant_id' => 999999, 'quantity' => -1]]], 'lines.0.variant_id'],
    'unknown location' => [['reason' => 'damage', 'location_id' => 999, 'lines' => [['variant_id' => 1, 'quantity' => -1]]], 'location_id'],
]);

it('refuses a duplicate variant line', function (): void {
    invAdjust(['reason' => 'loss', 'lines' => [['variant_id' => $this->doll->id, 'quantity' => -1], ['variant_id' => $this->doll->id, 'quantity' => -1]]])
        ->assertUnprocessable()->assertJsonFragment(['field' => 'lines.0.variant_id']);
});

it('refuses an adjustment that would leave less than is reserved, with a 422 on the line', function (): void {
    invAdjust(['reason' => 'damage', 'lines' => [['variant_id' => $this->puzzle->id, 'quantity' => -7]]])
        ->assertUnprocessable()->assertJsonFragment(['field' => 'lines.0.quantity']);

    expect(StockLevel::query()->where('variant_id', $this->puzzle->id)->value('on_hand'))->toBe(8);
});

it('answers adjustment errors in Bangla', function (): void {
    $this->withHeader('Accept-Language', 'bn');

    $message = invAdjust(['reason' => 'found', 'lines' => [['variant_id' => $this->doll->id, 'quantity' => -1]]])
        ->assertUnprocessable()->json('errors.0.message');

    expect($message)->toBe('এই কারণে শুধু স্টক যোগ করা যায়।');
});
