<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Marketing\Models\Coupon;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\RoleMatrix;

/*
 * Admin coupons API (P4-CRT-04): CRUD, validation, and the 403 role matrix (rule 21).
 */

function couponBody(array $overrides = []): array
{
    return [
        'code' => 'eid-ul-fitr',
        'description' => 'ঈদের ছাড়',
        'type' => 'percent',
        'value' => '15',
        'max_discount' => '500.00',
        'min_order_amount' => '1000.00',
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 1,
        'starts_at' => '2026-11-20T00:00:00Z',
        'ends_at' => '2026-11-30T00:00:00Z',
        'applies_to' => 'all',
        ...$overrides,
    ];
}

function actingAsMarketer(): void
{
    (new RolesAndPermissionsSeeder)->run();
    test()->actingAs(User::factory()->withRole(Role::SuperAdmin)->create()->refresh(), 'web');
}

it('creates a coupon, upper-casing its code', function (): void {
    actingAsMarketer();

    $response = $this->postJson('/api/v1/admin/coupons', couponBody())->assertCreated();

    expect($response->json())->toMatchArray([
        'code' => 'EID-UL-FITR', 'description' => 'ঈদের ছাড়', 'type' => 'percent', 'value' => '15.00', 'max_discount' => '500.00',
        'min_order_amount' => '1000.00', 'usage_limit_total' => 100, 'usage_limit_per_customer' => 1, 'used_count' => 0,
        'starts_at' => '2026-11-20T00:00:00Z', 'ends_at' => '2026-11-30T00:00:00Z', 'applies_to' => 'all', 'scope_ids' => [],
        'first_order_only' => false, 'is_active' => true,
    ])->and(Coupon::query()->sole()->created_by)->not->toBeNull();
});

it('validates the coupon document', function (): void {
    actingAsMarketer();
    Coupon::factory()->create(['code' => 'TAKEN']);

    $fields = fn (array $body) => collect($this->postJson('/api/v1/admin/coupons', $body)->assertUnprocessable()->json('errors'))->pluck('field')->all();

    expect($fields(couponBody(['code' => 'taken'])))->toContain('code')
        ->and($fields(couponBody(['code' => 'has space'])))->toContain('code')
        ->and($fields(couponBody(['value' => '150'])))->toContain('value')
        ->and($fields(couponBody(['value' => 10.5])))->toContain('value')
        ->and($fields(couponBody(['ends_at' => '2026-11-01T00:00:00Z'])))->toContain('ends_at')
        ->and($fields(couponBody(['applies_to' => 'categories'])))->toContain('scope_ids')
        ->and($fields(couponBody(['applies_to' => 'categories', 'scope_ids' => [999_999]])))->toContain('scope_ids')
        ->and($fields(couponBody(['type' => 'bogus'])))->toContain('type');
});

it('scopes a coupon to categories with exclusions', function (): void {
    actingAsMarketer();
    $category = Category::factory()->create(['name_bn' => 'শিক্ষামূলক']);

    $this->postJson('/api/v1/admin/coupons', couponBody(['applies_to' => 'categories', 'scope_ids' => [$category->id], 'excludes' => ['brand_ids' => []]]))
        ->assertCreated()
        ->assertJsonPath('scope_ids', [$category->id]);
});

it('lists with allowlisted filters and sorts only', function (): void {
    actingAsMarketer();
    Coupon::factory()->create(['code' => 'ALPHA']);
    Coupon::factory()->create(['code' => 'BETA', 'is_active' => false]);

    $this->getJson('/api/v1/admin/coupons?filter[status]=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ALPHA');
    $this->getJson('/api/v1/admin/coupons?filter[search]=bet')->assertOk()->assertJsonPath('data.0.code', 'BETA');
    $this->getJson('/api/v1/admin/coupons?sort=code')->assertOk()->assertJsonPath('data.0.code', 'ALPHA');
    $this->getJson('/api/v1/admin/coupons?filter[used_count]=1')->assertStatus(400);
    $this->getJson('/api/v1/admin/coupons?sort=value')->assertStatus(400);
    $this->getJson('/api/v1/admin/coupons?filter[status]=maybe')->assertStatus(400);
});

it('updates and deactivates (never deletes) a coupon', function (): void {
    actingAsMarketer();
    $coupon = Coupon::factory()->create(['code' => 'KEEP']);

    $this->putJson("/api/v1/admin/coupons/{$coupon->id}", couponBody(['code' => 'keep', 'type' => 'fixed', 'value' => '200']))
        ->assertOk()->assertJsonPath('type', 'fixed')->assertJsonPath('value', '200.00');
    $this->deleteJson("/api/v1/admin/coupons/{$coupon->id}")->assertOk()->assertJsonPath('is_active', false);
    $this->deleteJson("/api/v1/admin/coupons/{$coupon->id}")->assertOk();

    expect(Coupon::query()->count())->toBe(1);
});

it('guards GET /coupons with marketing.view', function (): void {
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson('/api/v1/admin/coupons'));
});

it('guards GET /coupons/{coupon} with marketing.view', function (): void {
    $coupon = Coupon::factory()->create();
    RoleMatrix::assert($this, Permission::MarketingView, fn () => $this->getJson("/api/v1/admin/coupons/{$coupon->id}"));
});

it('guards POST /coupons with marketing.manage', function (): void {
    $n = 0;
    RoleMatrix::assert($this, Permission::MarketingManage, function () use (&$n) {
        $n++;

        return $this->postJson('/api/v1/admin/coupons', couponBody(['code' => "ROLE{$n}"]));
    }, 201);
});

it('guards PUT /coupons/{coupon} with marketing.manage', function (): void {
    $coupon = Coupon::factory()->create(['code' => 'ROLEPUT']);
    RoleMatrix::assert($this, Permission::MarketingManage, fn () => $this->putJson("/api/v1/admin/coupons/{$coupon->id}", couponBody(['code' => 'ROLEPUT'])));
});

it('guards DELETE /coupons/{coupon} with marketing.manage', function (): void {
    $coupon = Coupon::factory()->create();
    RoleMatrix::assert($this, Permission::MarketingManage, fn () => $this->deleteJson("/api/v1/admin/coupons/{$coupon->id}"));
});

it('refuses the storefront bearer token on the admin coupons API', function (): void {
    $token = User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/admin/coupons')->assertStatus(403);
});
