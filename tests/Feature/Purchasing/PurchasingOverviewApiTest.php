<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Purchasing\Actions\CancelPurchase;
use App\Domain\Purchasing\Actions\RecordSupplierPayment;
use App\Domain\Purchasing\DTOs\SupplierPaymentData;
use App\Domain\Purchasing\Enums\SupplierPaymentMethod;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Purchasing\PurchasingKit as Kit;
use Tests\Support\RoleMatrix;

/*
 * The Purchase orders premium pass (Phase 10, Figma 414:70079 / 415:70452 / 417:71096): the KPI
 * summary, the grouped status and expected-date filters, the counts for the All / Closed tabs,
 * the list row's progress and paid flags, the PO's payment due date, and the New PO preview quote.
 * Dates are Dhaka dates (rule 11); weeks run Saturday–Friday.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');

    // Everything is raised and received on Thursday 12 Nov (Dhaka), then read on Monday 16 Nov.
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00', 'Asia/Dhaka'));
    $this->a = Supplier::factory()->create(['name' => 'বংশাল কিডস · Bangshal Kids Imports', 'payment_terms_days' => 15]);
    $this->b = Supplier::factory()->create(['name' => 'Islampur Wholesale']);
    $this->c = Supplier::factory()->create(['name' => 'Chawkbazar Toy House', 'opening_balance' => '300.00']);
    $this->d = Supplier::factory()->create(['name' => 'Advance Toys']);
    $this->v1 = ProductVariant::factory()->create();
    $this->v2 = ProductVariant::factory()->create();

    $this->arriving = Kit::purchase([[$this->v1, 10, '100']], $this->a);
    $this->arriving->update(['expected_date' => '2026-11-19']);
    $this->late = Kit::purchase([[$this->v2, 5, '200']], $this->a);
    $this->late->update(['expected_date' => '2026-11-14']);
    $this->partial = Kit::purchase([[$this->v1, 10, '50']], $this->a);
    Kit::receive($this->partial, [$this->partial->items[0]->id => 4]);
    $this->draft = Kit::purchase([[$this->v1, 1, '10']], $this->b, ordered: false);
    $this->cancelled = app(CancelPurchase::class)->handle(Kit::purchase([[$this->v2, 1, '10']], $this->b));
    $this->paid = Kit::purchase([[$this->v2, 2, '100']], $this->b);
    Kit::receive($this->paid, [$this->paid->items[0]->id => 2]);
    $pay = app(RecordSupplierPayment::class);
    $pay->handle($this->b, new SupplierPaymentData(Money::of('200'), SupplierPaymentMethod::Cash, CarbonImmutable::parse('2026-11-12'), $this->paid->id));
    // An advance to a supplier nothing was received from: it must not net off what we owe the others.
    $pay->handle($this->d, new SupplierPaymentData(Money::of('1000'), SupplierPaymentMethod::Cash, CarbonImmutable::parse('2026-11-12')));

    $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00', 'Asia/Dhaka'));
});

it('summarises open orders, this week\'s arrivals, late orders and what we owe, in Dhaka dates', function (): void {
    $this->getJson('/api/v1/admin/purchases/summary')
        ->assertOk()
        ->assertExactJson([
            'today' => '2026-11-16',
            'week_ends' => '2026-11-20',
            'open_count' => 3,
            'open_value' => '2500.00',
            'arriving_count' => 1,
            'late_count' => 1,
            // Supplier A owes 4 × 50 received; C its opening balance; D's advance is not netted.
            'owed' => '500.00',
            'owed_suppliers' => 2,
        ]);
});

it('ends the week on Friday: on a Saturday this week runs to the next Friday', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-21 00:30', 'Asia/Dhaka'));

    $this->getJson('/api/v1/admin/purchases/summary')
        ->assertOk()
        ->assertJsonPath('today', '2026-11-21')
        ->assertJsonPath('week_ends', '2026-11-27')
        ->assertJsonPath('arriving_count', 0)
        ->assertJsonPath('late_count', 2);
});

it('hides the open value without catalog.view_cost and the amount owed without finance.view', function (): void {
    foreach ([Role::Accountant, Role::InventoryStaff, Role::Viewer, Role::OrderOperator] as $role) {
        RoleMatrix::assertFor($this, $role, RoleMatrix::allows($role, Permission::PurchasingView), function () use ($role) {
            $response = $this->getJson('/api/v1/admin/purchases/summary');
            if ($response->status() === 200) {
                expect($response->json('open_count'))->toBe(3)
                    ->and($response->json('open_value') !== null)->toBe(RoleMatrix::allows($role, Permission::CatalogViewCost))
                    ->and($response->json('owed') !== null)->toBe(RoleMatrix::allows($role, Permission::FinanceView))
                    ->and($response->json('owed_suppliers') !== null)->toBe(RoleMatrix::allows($role, Permission::FinanceView));
            }

            return $response;
        });
    }
});

it('counts the All and Closed tabs alongside every status', function (): void {
    $this->getJson('/api/v1/admin/purchases/counts')
        ->assertOk()
        ->assertJsonPath('all', 6)
        ->assertJsonPath('closed', 1)
        ->assertJsonPath('ordered', 2)
        ->assertJsonPath('partially_received', 1)
        ->assertJsonPath('received', 1)
        ->assertJsonPath('draft', 1)
        ->assertJsonPath('cancelled', 1);
});

it('filters by the open and closed status groups and by late / this week, and 400s anything else', function (): void {
    $ids = fn (string $query): array => collect($this->getJson('/api/v1/admin/purchases?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('filter[status]=open'))->toBe(collect([$this->arriving->id, $this->late->id, $this->partial->id])->sort()->values()->all())
        ->and($ids('filter[status]=closed'))->toBe([$this->cancelled->id])
        ->and($ids('filter[expected]=late'))->toBe([$this->late->id])
        ->and($ids('filter[expected]=this_week'))->toBe([$this->arriving->id])
        ->and($ids('filter[status]=open&filter[supplier_id]='.$this->a->id.'&filter[search]='.urlencode((string) $this->late->number)))->toBe([$this->late->id]);

    $this->getJson('/api/v1/admin/purchases?filter[status]=waiting')->assertStatus(400);
    $this->getJson('/api/v1/admin/purchases?filter[expected]=tomorrow')->assertStatus(400);
    $this->getJson('/api/v1/admin/purchases?filter[late]=1')->assertStatus(400);
});

it('gives each row its received progress, the late flag and the paid flag', function (): void {
    $rows = collect($this->getJson('/api/v1/admin/purchases')->assertOk()->json('data'))->keyBy('id');

    expect($rows[$this->partial->id])->toMatchArray(['units_ordered' => 10, 'units_received' => 4, 'is_late' => false, 'is_paid' => false, 'due_amount' => '200.00'])
        ->and($rows[$this->late->id])->toMatchArray(['units_received' => 0, 'is_late' => true])
        ->and($rows[$this->paid->id])->toMatchArray(['units_ordered' => 2, 'units_received' => 2, 'is_paid' => true, 'due_amount' => '0.00'])
        ->and($rows[$this->draft->id]['is_late'])->toBeFalse();
});

it('dates the payment from the first delivery plus the supplier\'s terms, and only while something is owed', function (): void {
    $this->getJson("/api/v1/admin/purchases/{$this->partial->id}")
        ->assertOk()
        ->assertJsonPath('payment_due_date', '2026-11-27')
        ->assertJsonPath('is_paid', false)
        ->assertJsonPath('is_late', false);
    $this->getJson("/api/v1/admin/purchases/{$this->paid->id}")->assertOk()->assertJsonPath('payment_due_date', null)->assertJsonPath('is_paid', true);
    $this->getJson("/api/v1/admin/purchases/{$this->late->id}")->assertOk()->assertJsonPath('payment_due_date', null)->assertJsonPath('is_late', true);
});

it('quotes a new PO\'s line totals and order total on the server, with the supplier\'s last price', function (): void {
    $fresh = ProductVariant::factory()->create();

    $this->postJson('/api/v1/admin/purchases/quote', [
        'supplier_id' => $this->a->id,
        'lines' => [
            ['variant_id' => $this->v1->id, 'quantity' => 30, 'unit_cost' => '298', 'discount' => '40'],
            ['variant_id' => $fresh->id, 'quantity' => 3, 'unit_cost' => '333.3333'],
        ],
    ])->assertOk()
        ->assertJsonPath('products', 2)
        ->assertJsonPath('units', 33)
        ->assertJsonPath('subtotal', '9940.00')
        ->assertJsonPath('discount', '40.00')
        ->assertJsonPath('total', '9900.00')
        ->assertJsonPath('supplier_known', true)
        ->assertJsonPath('lines.0.line_total', '8900.00')
        // The newest placed PO from supplier A for v1 is the partial one at 50.
        ->assertJsonPath('lines.0.last_unit_cost', '50.0000')
        ->assertJsonPath('lines.1.line_total', '1000.00')
        ->assertJsonPath('lines.1.last_unit_cost', null);

    expect(Purchase::query()->count())->toBe(6);
});

it('ignores a draft or cancelled PO for the last price, and quotes without a supplier', function (): void {
    $this->postJson('/api/v1/admin/purchases/quote', ['supplier_id' => $this->b->id, 'lines' => [['variant_id' => $this->v1->id, 'quantity' => 1, 'unit_cost' => '10']]])
        ->assertOk()->assertJsonPath('lines.0.last_unit_cost', null);
    $this->postJson('/api/v1/admin/purchases/quote', ['lines' => [['variant_id' => $this->v1->id, 'quantity' => 2, 'unit_cost' => '12.5']]])
        ->assertOk()->assertJsonPath('total', '25.00')->assertJsonPath('supplier_known', false);
});

it('refuses a quote line whose discount exceeds its value, and invalid lines', function (): void {
    $this->postJson('/api/v1/admin/purchases/quote', ['lines' => [['variant_id' => $this->v1->id, 'quantity' => 1, 'unit_cost' => '10', 'discount' => '11']]])
        ->assertUnprocessable()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/validation-error');
    $this->postJson('/api/v1/admin/purchases/quote', ['lines' => []])->assertUnprocessable();
    $this->postJson('/api/v1/admin/purchases/quote', ['lines' => [['variant_id' => $this->v1->id, 'quantity' => 0, 'unit_cost' => '1.12345']]])->assertUnprocessable();
});
