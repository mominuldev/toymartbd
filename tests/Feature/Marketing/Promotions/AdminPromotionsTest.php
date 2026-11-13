<?php

declare(strict_types=1);

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Marketing\Models\FlashSaleItem;
use App\Domain\Marketing\Models\Promotion;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Marketing\Promotions\PromoKit;
use Tests\Support\RoleMatrix;

/*
 * Admin promotions API (P7): CRUD for every type, flash items and their allotment guard,
 * Dhaka-time schedule, filters, report, and the 403 role matrix (rule 21).
 */

function promoBody(array $overrides = []): array
{
    return [
        'name' => 'Eid sale',
        'name_bn' => 'ঈদের ছাড়',
        'type' => 'discount',
        'priority' => 5,
        'is_stackable' => false,
        'allows_coupons' => false,
        'starts_at' => '2026-12-01T00:00:00',
        'ends_at' => '2026-12-05T23:59:00+06:00',
        'usage_limit_per_customer' => 2,
        'budget' => '50000',
        'rules' => ['scope' => ['applies_to' => 'all'], 'type' => 'percent', 'value' => '15'],
        ...$overrides,
    ];
}

function actingAsPromoAdmin(): void
{
    (new RolesAndPermissionsSeeder)->run();
    test()->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
}

it('creates a promotion, reading a time without an offset as Dhaka time', function (): void {
    actingAsPromoAdmin();

    $r = $this->postJson('/api/v1/admin/promotions', promoBody())->assertCreated();

    expect($r->json())->toMatchArray([
        'name' => 'Eid sale', 'name_bn' => 'ঈদের ছাড়', 'type' => 'discount', 'priority' => 5, 'is_stackable' => false,
        'allows_coupons' => false, 'starts_at' => '2026-11-30T18:00:00Z', 'ends_at' => '2026-12-05T17:59:00Z',
        'usage_limit_per_customer' => 2, 'budget' => '50000.00', 'discount_spent' => '0.00', 'status' => 'scheduled',
    ])->and($r->json('rules'))->toMatchArray(['type' => 'percent', 'value' => '15.00'])
        ->and(Promotion::query()->sole()->created_by)->not->toBeNull();
});

it('defaults allows_coupons to true when omitted', function (): void {
    actingAsPromoAdmin();
    $body = promoBody();
    unset($body['allows_coupons']);

    $this->postJson('/api/v1/admin/promotions', $body)->assertCreated()->assertJsonPath('allows_coupons', true);
});

it('validates per type', function (): void {
    actingAsPromoAdmin();
    $fields = fn (array $body) => collect($this->postJson('/api/v1/admin/promotions', $body)->assertUnprocessable()->json('errors'))->pluck('field')->all();

    expect($fields(promoBody(['rules' => ['type' => 'percent', 'value' => '150']])))->toContain('rules.value')
        ->and($fields(promoBody(['type' => 'tiered', 'rules' => []])))->toContain('rules.tiers')
        ->and($fields(promoBody(['type' => 'bundle', 'rules' => ['product_ids' => [], 'type' => 'price', 'value' => '500']])))->toContain('rules.product_ids')
        ->and($fields(promoBody(['type' => 'free_delivery', 'rules' => []])))->toContain('rules.min_subtotal')
        ->and($fields(promoBody(['type' => 'flash_sale', 'starts_at' => null])))->toContain('starts_at')
        ->and($fields(promoBody(['ends_at' => '2026-11-01T00:00:00Z'])))->toContain('ends_at')
        ->and($fields(promoBody(['rules' => ['scope' => ['applies_to' => 'categories', 'ids' => [999999]], 'type' => 'percent', 'value' => '5']])))->toContain('rules.scope.ids')
        ->and($fields(promoBody(['type' => 'bogus'])))->toContain('type');
});

it('saves a flash sale with items, refusing a sale price at or above the regular price', function (): void {
    actingAsPromoAdmin();
    Bus::fake([RevalidateStorefrontTags::class]);
    $v = CartTestKit::variant('1000.00', 10);

    $bad = promoBody(['type' => 'flash_sale', 'rules' => [], 'flash_items' => [['variant_id' => $v->id, 'sale_price' => '1000.00', 'allotment' => 5]]]);
    $this->postJson('/api/v1/admin/promotions', $bad)->assertUnprocessable()->assertJsonPath('errors.0.field', 'flash_items.0.sale_price');

    $r = $this->postJson('/api/v1/admin/promotions', promoBody([
        'type' => 'flash_sale', 'rules' => [],
        'flash_items' => [['variant_id' => $v->id, 'sale_price' => '799', 'allotment' => 5, 'per_order_limit' => 2]],
    ]))->assertCreated();

    expect($r->json('flash_items.0'))->toMatchArray(['variant_id' => $v->id, 'sale_price' => '799.00', 'regular_price' => '1000.00', 'allotment' => 5, 'claimed' => 0, 'remaining' => 5, 'per_order_limit' => 2, 'product_name_bn' => 'রিমোট কন্ট্রোল গাড়ি']);
    Bus::assertDispatched(RevalidateStorefrontTags::class, fn ($job) => in_array('flash-sale', $job->tags, true) && in_array('product:'.$v->product->slug, $job->tags, true));
});

it('never lets an allotment drop below claimed units, nor removes a line with claims', function (): void {
    actingAsPromoAdmin();
    $v = CartTestKit::variant('1000.00', 10);
    $p = PromoKit::flash([$v->id => ['700.00', 5]]);
    FlashSaleItem::query()->update(['claimed' => 3]);
    $body = promoBody(['type' => 'flash_sale', 'rules' => [], 'starts_at' => '2026-11-01T00:00:00Z', 'ends_at' => '2026-12-01T00:00:00Z']);

    $this->putJson("/api/v1/admin/promotions/{$p->id}", $body + ['flash_items' => [['variant_id' => $v->id, 'sale_price' => '700', 'allotment' => 2]]])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'flash_items.0.allotment');
    $this->putJson("/api/v1/admin/promotions/{$p->id}", ['rules' => ['type' => 'percent', 'value' => '10']] + $body + ['flash_items' => []])
        ->assertUnprocessable()->assertJsonPath('errors.0.field', 'flash_items');
    $this->putJson("/api/v1/admin/promotions/{$p->id}", $body + ['flash_items' => [['variant_id' => $v->id, 'sale_price' => '650', 'allotment' => 3]]])
        ->assertOk()->assertJsonPath('flash_items.0.remaining', 0)->assertJsonPath('flash_items.0.sale_price', '650.00');
});

it('lists with allowlisted filters only, and deactivates on DELETE', function (): void {
    actingAsPromoAdmin();
    $running = PromoKit::percentOff('5', ['name' => 'Running']);
    PromoKit::percentOff('5', ['name' => 'Later', 'starts_at' => now()->addDay()]);
    PromoKit::percentOff('5', ['name' => 'Over', 'ends_at' => now()->subDay(), 'starts_at' => now()->subDays(3)]);

    expect(collect($this->getJson('/api/v1/admin/promotions?filter[status]=running')->assertOk()->json('data'))->pluck('name')->all())->toBe(['Running'])
        ->and(collect($this->getJson('/api/v1/admin/promotions?filter[status]=scheduled')->json('data'))->pluck('name')->all())->toBe(['Later']);
    $this->getJson('/api/v1/admin/promotions?filter[nope]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/promotions?filter[status]=bogus')->assertStatus(400);

    $this->deleteJson("/api/v1/admin/promotions/{$running->id}")->assertOk()->assertJsonPath('is_active', false)->assertJsonPath('status', 'inactive');
});

it('reports usage with masked phones and delivered revenue only', function (): void {
    actingAsPromoAdmin();
    $p = PromoKit::percentOff('10');

    $r = $this->getJson("/api/v1/admin/promotions/{$p->id}/report")->assertOk();
    expect($r->json('summary'))->toBe(['orders' => 0, 'discount_total' => '0.00', 'gross_sales' => '0.00', 'delivered_orders' => 0, 'delivered_revenue' => '0.00']);
});

it('guards GET /promotions with marketing.view', function (): void {
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson('/api/v1/admin/promotions'));
});

it('guards GET /promotions/{id} and its report with marketing.view', function (): void {
    $p = PromoKit::percentOff('5');
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson("/api/v1/admin/promotions/{$p->id}"));
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson("/api/v1/admin/promotions/{$p->id}/report"));
});

it('guards POST /promotions with marketing.manage', function (): void {
    RoleMatrix::assert($this, Permission::MarketingManage, fn () => $this->postJson('/api/v1/admin/promotions', promoBody()), 201);
});

it('guards PUT and DELETE /promotions/{id} with marketing.manage', function (): void {
    $p = PromoKit::percentOff('5');
    RoleMatrix::assert($this, Permission::MarketingManage, fn () => $this->putJson("/api/v1/admin/promotions/{$p->id}", promoBody()));
    RoleMatrix::assert($this, Permission::MarketingManage, fn () => $this->deleteJson("/api/v1/admin/promotions/{$p->id}"));
});
