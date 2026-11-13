<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Feature\Cart\CartTestKit;
use Tests\Support\RoleMatrix;

/*
 * Coupon / promotion builder lookups under marketing permissions (P7 follow-up): the flash-sale
 * variant picker no longer needs orders.create, and saved scope ids resolve to names.
 */

/** A marketing user who may build promotions but not place orders. */
function marketingOnly(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create()->refresh();
    $user->givePermissionTo([Permission::MarketingView->value, Permission::MarketingManage->value]);
    test()->actingAs($user, 'web');

    return $user;
}

it('searches variants for flash-sale lines without orders.create', function (): void {
    $user = marketingOnly();
    expect($user->can(Permission::OrdersCreate->value))->toBeFalse();
    $variant = CartTestKit::variant('750.00', 4);

    $this->getJson('/api/v1/admin/orders/compose/variants?q=Remote')->assertForbidden();
    $json = $this->getJson('/api/v1/admin/marketing/lookups/variants?q=Remote')->assertOk()->json('data');

    expect($json[0])->toMatchArray(['variant_id' => $variant->id, 'price' => '750.00', 'available' => 4])
        ->and($this->getJson('/api/v1/admin/marketing/lookups/variants?q='.urlencode('রিমোট'))->json('data.0.variant_id'))->toBe($variant->id);
    $this->getJson('/api/v1/admin/marketing/lookups/variants?q=R')->assertUnprocessable();
});

it('names saved scope ids per kind, archived rows included, unknown ids left out', function (): void {
    marketingOnly();
    $a = Product::factory()->withoutVariants()->create(['name' => 'LEGO Classic', 'name_bn' => 'লেগো ক্লাসিক']);
    $b = Product::factory()->withoutVariants()->create(['name' => 'Rubik Cube', 'name_bn' => null]);
    $b->delete();
    $category = Category::factory()->create(['name' => 'Puzzles', 'name_bn' => 'ধাঁধা']);
    $brand = Brand::factory()->create(['name' => 'LEGO', 'name_bn' => 'লেগো']);

    $this->getJson("/api/v1/admin/marketing/lookups/names?kind=products&ids={$b->id},{$a->id},999999")
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $a->id, 'name' => 'LEGO Classic', 'name_bn' => 'লেগো ক্লাসিক'],
            ['id' => $b->id, 'name' => 'Rubik Cube', 'name_bn' => null],
        ]]);
    $this->getJson("/api/v1/admin/marketing/lookups/names?kind=categories&ids={$category->id}")->assertOk()->assertJsonPath('data.0.name_bn', 'ধাঁধা');
    $this->getJson("/api/v1/admin/marketing/lookups/names?kind=brands&ids={$brand->id}")->assertOk()->assertJsonPath('data.0.name', 'LEGO');

    $this->getJson('/api/v1/admin/marketing/lookups/names?kind=orders&ids=1')->assertUnprocessable();
    $this->getJson('/api/v1/admin/marketing/lookups/names?kind=products&ids=1;DROP')->assertUnprocessable();
});

it('guards the variant lookup with marketing.manage', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/marketing/lookups/variants?q=Remote'));
})->with(RoleMatrix::dataset(Permission::MarketingManage));

it('guards the name lookup with marketing.view', function (Role $role, bool $allowed): void {
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->getJson('/api/v1/admin/marketing/lookups/names?kind=brands&ids=1'));
})->with(RoleMatrix::dataset(Permission::MarketingView));
