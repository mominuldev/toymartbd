<?php

declare(strict_types=1);

use App\Domain\Ai\Exceptions\AiException;
use App\Domain\Ai\Models\AiRequest;
use App\Domain\Ai\Restock\RestockForecast;
use App\Domain\Ai\Services\AiDrivers;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role as RoleModel;
use Tests\Feature\Purchasing\PurchasingKit as Kit;
use Tests\Support\RoleMatrix;

/*
 * Inventory › Restock forecast (Figma 540:83487): the arithmetic is the server's and no AI is
 * involved in it; AI writes only the short note, checked by FigureGuard. Read on Monday
 * 16 Nov 2026 (Dhaka), so the 30-day sales window is 18 Oct – 16 Nov inclusive (rule 11).
 */

const RESTOCK_URL = '/api/v1/admin/inventory/restock';

/** Units of a variant on one order placed at a Dhaka wall-clock time. */
function restockSale(ProductVariant $variant, int $units, string $dhaka, OrderStatus $status = OrderStatus::Delivered, string $source = 'web'): void
{
    $order = Order::factory()->status($status)->create(['placed_at' => CarbonImmutable::parse($dhaka, 'Asia/Dhaka')->utc(), 'source' => $source]);
    OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => $units]);
}

function restockConnectAi(): void
{
    Setting::put('ai.key.anthropic', 'sk-ant-api03-test-0000', SettingGroup::Ai, true);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->fake = AiDrivers::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');

    $this->supplier = Supplier::factory()->create(['name' => 'বংশাল কিডস · Bangshal Kids Imports']);

    // Lead time history: a PO placed 1 Nov, fully received 6 Nov (Dhaka) → 5 days.
    $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00', 'Asia/Dhaka'));
    $history = Kit::purchase([[ProductVariant::factory()->create(), 1, '10']], $this->supplier);
    Kit::receive($history, [$history->items[0]->id => 1]);
    Purchase::query()->whereKey($history->id)->update(['order_date' => '2026-11-01']);

    // A: Kids Tricycle · Red — 120 sold (4.00 a day), 12 available, 10 still on an open PO at ৳700.50.
    $this->a = ProductVariant::factory()->create(['sku' => 'TRI-RED', 'name' => 'Red', 'price' => '1250.00']);
    $this->a->product->update(['name' => 'Kids Tricycle', 'name_bn' => 'বাচ্চাদের ট্রাইসাইকেল']);
    Kit::open($this->a->id, 12);
    Kit::purchase([[$this->a, 10, '700.5']], $this->supplier);

    // B: never bought (lead time a guess: 7 days), 15 sold in the window (0.50 a day), 9 available.
    $this->b = ProductVariant::factory()->create(['sku' => 'CRY-24', 'price' => '499.99']);
    $this->b->product->update(['name' => 'Crayons 24 colours']);
    Kit::open($this->b->id, 9);

    // C: zero rate — nothing sold, nothing in stock. D: sells slowly, plenty in stock.
    $this->c = ProductVariant::factory()->create(['sku' => 'ZERO-1']);
    $this->d = ProductVariant::factory()->create(['sku' => 'SLOW-1']);
    Kit::open($this->d->id, 100);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Asia/Dhaka'));
    restockSale($this->a, 100, '2026-11-10 12:00');
    restockSale($this->a, 20, '2026-11-15 18:00', source: 'pos');
    restockSale($this->a, 500, '2026-11-14 12:00', OrderStatus::Cancelled);
    // The window's edge: 23:30 on 17 Oct (Dhaka) is outside, 00:10 on 18 Oct is inside.
    restockSale($this->b, 50, '2026-10-17 23:30');
    restockSale($this->b, 1, '2026-10-18 00:10');
    restockSale($this->b, 14, '2026-11-16 09:00', OrderStatus::Pending);
    restockSale($this->d, 3, '2026-11-01 12:00');
});

it('computes the forecast from integer units: rate, run-out, lead time, open PO and suggestion', function (): void {
    $res = $this->getJson(RESTOCK_URL.'?horizon=14')->assertOk()
        ->assertJsonPath('horizon', 14)
        ->assertJsonPath('today', '2026-11-16')
        ->assertJsonPath('window', ['from' => '2026-10-18', 'to' => '2026-11-16', 'days' => 30])
        ->assertJsonCount(2, 'rows');

    // A: floor(30×12/120) = 3 days; ceil(4×(5+14)) = 76 − 12 − 10 on order = 54; short ceil(4×14) − 22 = 34.
    expect($res->json('rows.0'))->toMatchArray([
        'sku' => 'TRI-RED',
        'product_name' => 'Kids Tricycle',
        'variant_name' => 'Red',
        'in_stock' => 12,
        'units_sold' => 120,
        'sells_per_day' => '4.00',
        'runs_out_in_days' => 3,
        'lead_time_days' => 5,
        'lead_time_estimated' => false,
        'open_po_quantity' => 10,
        'suggested_order' => 54,
        'units_short' => 34,
        'confidence' => 'high',
        'sales_at_risk' => '42500.00',
        'supplier' => ['id' => $this->supplier->id, 'name' => 'বংশাল কিডস · Bangshal Kids Imports'],
        'last_unit_cost' => '700.5000',
        'suggested_order_value' => '37827.00',
    ]);
    // B: floor(30×9/15) = 18 ≤ 14 + 7; ceil(0.5×21) = 11 − 9 = 2; nothing short within 14 days.
    expect($res->json('rows.1'))->toMatchArray([
        'sku' => 'CRY-24',
        'units_sold' => 15,
        'sells_per_day' => '0.50',
        'runs_out_in_days' => 18,
        'lead_time_days' => 7,
        'lead_time_estimated' => true,
        'open_po_quantity' => 0,
        'suggested_order' => 2,
        'units_short' => 0,
        'confidence' => 'medium',
        'sales_at_risk' => '0.00',
        'supplier' => null,
        'last_unit_cost' => null,
        'suggested_order_value' => null,
    ]);
    $res->assertJsonPath('kpis', ['running_out' => 1, 'before_arrival' => 1, 'sales_at_risk' => '42500.00', 'suggested_order_value' => '37827.00']);
    expect(AiRequest::query()->count())->toBe(0);
});

it('widens with the horizon and keeps a zero-rate or slow variant out', function (): void {
    $res = $this->getJson(RESTOCK_URL.'?horizon=30')->assertOk();
    expect(array_column($res->json('rows'), 'sku'))->toBe(['TRI-RED', 'CRY-24']);
    // A at 30 days: ceil(4×35) = 140 − 22 = 118.
    expect($res->json('rows.0.suggested_order'))->toBe(118)
        ->and($res->json('kpis.running_out'))->toBe(2);

    $this->getJson(RESTOCK_URL.'?horizon=7')->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.sku', 'TRI-RED');
    $this->getJson(RESTOCK_URL)->assertOk()->assertJsonPath('horizon', 14);
});

it('answers 400 for a horizon off the list or any other parameter', function (string $query): void {
    $this->getJson(RESTOCK_URL.'?'.$query)->assertStatus(400)->assertJsonPath('type', 'https://toymartbd.com/problems/bad-request');
})->with(['horizon=10', 'horizon=abc', 'horizon[]=7', 'horizon=14&sort=sku', 'filter[location_id]=1']);

it('hides every cost figure without catalog.view_cost', function (): void {
    $role = RoleModel::findByName(Role::Viewer->value, 'web');
    $this->actingAs(User::factory()->withRole(Role::Viewer)->create()->refresh(), 'web');
    expect($role->hasPermissionTo(Permission::CatalogViewCost->value))->toBeFalse();

    $this->getJson(RESTOCK_URL)->assertOk()
        ->assertJsonPath('kpis.suggested_order_value', null)
        ->assertJsonPath('kpis.sales_at_risk', '42500.00')
        ->assertJsonPath('rows.0.last_unit_cost', null)
        ->assertJsonPath('rows.0.suggested_order_value', null)
        ->assertJsonPath('rows.0.suggested_order', 54);
});

describe('the AI note', function (): void {
    it('writes at most three sentences from the forecast figures, caches them, and never sends cost', function (): void {
        restockConnectAi();
        $this->fake->respond(['sentences' => [
            'Order Kids Tricycle · Red first: it runs out in 3 days and the supplier takes 5.',
            'Order 54 more, on top of the 10 already on order.',
            'Crayons 24 colours can wait: 18 days of stock.',
            'A fourth sentence is dropped.',
        ]]);

        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertOk()
            ->assertJsonCount(3, 'sentences')
            ->assertJsonPath('model', 'claude-opus-5-5')
            ->assertJsonPath('omitted', null);

        $sent = $this->fake->sent[0]['prompt']->user;
        expect($sent)->toContain('Kids Tricycle · Red')->not->toContain('700.5')->not->toContain('37827');

        // Cached: the GET shows it and a second POST makes no provider call.
        $this->getJson(RESTOCK_URL)->assertJsonPath('note.sentences.1', 'Order 54 more, on top of the 10 already on order.');
        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertOk()->assertJsonCount(3, 'sentences');
        expect($this->fake->sent)->toHaveCount(1)->and(AiRequest::query()->count())->toBe(1);

        // A new sale moves the figures: the old note is no longer shown.
        restockSale($this->b, 1, '2026-11-16 09:30');
        $this->getJson(RESTOCK_URL)->assertJsonPath('note', null);
    });

    it('rejects a figure the forecast does not contain, retries once, then omits the note', function (): void {
        restockConnectAi();
        $this->fake->respond(
            ['sentences' => ['Order 75 Kids Tricycles today.']],
            ['sentences' => ['Kids Tricycle is 31% of sales.']],
        );

        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertOk()
            ->assertJsonPath('sentences', [])
            ->assertJsonPath('omitted', 'unverified_figures');
        expect($this->fake->sent)->toHaveCount(2);
        $this->getJson(RESTOCK_URL)->assertJsonPath('note', null);
    });

    it('accepts the retry when it sticks to the figures', function (): void {
        restockConnectAi();
        $this->fake->respond(['sentences' => ['Order 75 now.']], ['sentences' => ['Order 54 Kids Tricycle · Red now; they last 3 days.']]);

        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertOk()->assertJsonPath('sentences.0', 'Order 54 Kids Tricycle · Red now; they last 3 days.');
    });

    it('writes no note when figures may not be shared, and the forecast still works', function (): void {
        restockConnectAi();
        Setting::put('ai.share', ['figures' => false, 'products' => true, 'first_names' => true], SettingGroup::Ai);

        $this->getJson(RESTOCK_URL)->assertOk()->assertJsonCount(2, 'rows')->assertJsonPath('note_blocked', 'figures_not_shared');
        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertOk()->assertJsonPath('omitted', 'figures_not_shared');
        expect($this->fake->sent)->toBe([]);
    });

    it('sends SKUs, not product names, when product details may not be shared', function (): void {
        restockConnectAi();
        Setting::put('ai.share', ['figures' => true, 'products' => false, 'first_names' => true], SettingGroup::Ai);
        $this->fake->respond(['sentences' => ['Order TRI-RED first.']]);

        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertOk();
        expect($this->fake->sent[0]['prompt']->user)->toContain('TRI-RED')->not->toContain('Kids Tricycle');
    });

    it('maps provider problems to RFC 9457 types', function (): void {
        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertStatus(409)->assertJsonPath('type', 'https://toymartbd.com/problems/ai-not-configured');

        restockConnectAi();
        $this->fake->respond(new AiException(AiException::PROVIDER, 'down'));
        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14])->assertStatus(503)->assertJsonPath('type', 'https://toymartbd.com/problems/ai-unavailable');
    });

    it('validates the horizon', function (): void {
        $this->postJson(RESTOCK_URL.'/note', ['horizon' => 10])->assertStatus(422)->assertJsonPath('errors.0.field', 'horizon');
    });
});

it('has no float in its money path', function (): void {
    expect(RestockForecast::rate(1))->toBe('0.03')
        ->and(RestockForecast::rate(2))->toBe('0.07')
        ->and(RestockForecast::rate(45))->toBe('1.50')
        ->and(RestockForecast::confidence(29))->toBe('medium')
        ->and(RestockForecast::confidence(9))->toBe('low');
});

describe('role matrix', function (): void {
    it('guards GET /inventory/restock with inventory.view', function (Role $role, bool $allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson(RESTOCK_URL));
    })->with(RoleMatrix::dataset(Permission::InventoryView));

    it('guards POST /inventory/restock/note with inventory.view', function (Role $role, bool $allowed): void {
        Setting::put('ai.share', ['figures' => false, 'products' => true, 'first_names' => true], SettingGroup::Ai);
        RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(RESTOCK_URL.'/note', ['horizon' => 14]));
    })->with(RoleMatrix::dataset(Permission::InventoryView));

    it('refuses a guest with 401', function (): void {
        auth()->guard('web')->logout();
        $this->getJson(RESTOCK_URL)->assertUnauthorized();
    });
});
