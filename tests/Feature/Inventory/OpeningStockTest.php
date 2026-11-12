<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Support\CatalogFixtures;

/*
 * Opening stock on product create (P4-CRT-05): `variants[i].opening_stock` becomes an
 * `opening_balance` movement; later edits show stock read-only (adjustments: Phase 5).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withRole(Role::Manager)->create()->refresh();
    $this->actingAs($this->manager, 'web');
});

/** @param  array<string, mixed>  $overrides */
function stockedPayload(array $overrides = []): array
{
    $category = Category::factory()->create();

    return [
        'name' => 'Wooden Train Set', 'name_bn' => 'কাঠের ট্রেন সেট', 'type' => 'simple', 'status' => 'active',
        'primary_category_id' => $category->id, 'category_ids' => [$category->id],
        'variants' => [['price' => '2450.00', 'weight_grams' => 1200, 'opening_stock' => 12]],
        ...$overrides,
    ];
}

it('records opening stock for a simple product and shows it read-only', function (): void {
    $response = $this->postJson('/api/v1/admin/products', stockedPayload())->assertCreated();

    $variantId = $response->json('variants.0.id');
    $movement = StockMovement::query()->sole();

    expect($response->json('variants.0.stock'))->toBe(['on_hand' => 12, 'reserved' => 0, 'available' => 12])
        ->and($movement->variant_id)->toBe($variantId)
        ->and($movement->type)->toBe(StockMovementType::OpeningBalance)
        ->and($movement->quantity)->toBe(12)
        ->and($movement->balance_after)->toBe(12)
        ->and($movement->reference_type)->toBe('product')
        ->and($movement->reference_id)->toBe($response->json('id'))
        ->and($movement->user_id)->toBe($this->manager->id)
        ->and(StockLevel::query()->sole()->on_hand)->toBe(12);

    $this->getJson('/api/v1/admin/products/'.$response->json('id'))->assertOk()->assertJsonPath('variants.0.stock.available', 12);
});

it('matches opening stock to the variant matrix by combination', function (): void {
    ['color' => $color, 'colors' => $colors, 'size' => $size, 'sizes' => $sizes] = CatalogFixtures::colorAndSize();

    $response = $this->postJson('/api/v1/admin/products', stockedPayload([
        'type' => 'variable',
        'options' => [
            ['attribute_id' => $color->id, 'value_ids' => [$colors[0]->id, $colors[1]->id]],
            ['attribute_id' => $size->id, 'value_ids' => [$sizes[0]->id]],
        ],
        'variant_defaults' => ['price' => '1450'],
        // Rows in a different order from the matrix: matched by combination, not index.
        'variants' => [
            ['attribute_value_ids' => [$sizes[0]->id, $colors[1]->id], 'opening_stock' => 4],
            ['attribute_value_ids' => [$colors[0]->id, $sizes[0]->id], 'opening_stock' => 0],
        ],
    ]))->assertCreated();

    $stock = collect($response->json('variants'))->mapWithKeys(fn (array $v) => [$v['name'] => $v['stock']['on_hand']])->all();

    expect($stock)->toBe(['Red / Small' => 0, 'Blue / Small' => 4])
        ->and(StockMovement::query()->count())->toBe(1);
});

it('needs inventory.adjust to set opening stock above zero', function (): void {
    $role = Spatie\Permission\Models\Role::findOrCreate('catalog_only', 'web');
    $role->syncPermissions([Permission::CatalogView->value, Permission::CatalogCreate->value]);
    $this->actingAs(User::factory()->create()->assignRole('catalog_only')->refresh(), 'web');

    $this->postJson('/api/v1/admin/products', stockedPayload())->assertForbidden();
    expect(Product::query()->count())->toBe(0);

    // Zero or absent opening stock needs no inventory permission, and the stock block is hidden.
    $response = $this->postJson('/api/v1/admin/products', stockedPayload(['variants' => [['price' => '2450.00']]]))->assertCreated();
    expect($response->json('variants.0'))->not->toHaveKey('stock');
});

it('refuses opening stock on update — later changes are adjustments', function (): void {
    $id = $this->postJson('/api/v1/admin/products', stockedPayload())->assertCreated()->json('id');

    $this->putJson("/api/v1/admin/products/{$id}", stockedPayload(['variants' => [['price' => '2450.00', 'opening_stock' => 5]]]))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'variants.0.opening_stock');

    expect(StockLevel::query()->sole()->on_hand)->toBe(12);
});

it('validates opening stock as a whole number from 0', function (mixed $value): void {
    $this->postJson('/api/v1/admin/products', stockedPayload(['variants' => [['price' => '2450.00', 'opening_stock' => $value]]]))
        ->assertStatus(422)
        ->assertJsonPath('errors.0.field', 'variants.0.opening_stock');
})->with([-1, 1.5, 'ten', 1000001]);

it('records a branch opening balance at that location only, once per location', function (): void {
    $product = Product::factory()->create();
    $variant = $product->variants()->firstOrFail();
    $ctg = App\Domain\Inventory\Models\StockLocation::query()->create(['code' => 'ctg', 'name' => 'Chattogram · Agrabad', 'is_active' => true]);
    $record = app(App\Domain\Inventory\Actions\RecordOpeningStock::class);

    $record->handle($product, [$variant->id => 10]);
    $record->handle($product, [$variant->id => 4], locationId: $ctg->id);
    $record->handle($product, [$variant->id => 99], locationId: $ctg->id); // already opened there

    expect(StockLevel::query()->where('variant_id', $variant->id)->orderBy('location_id')->pluck('on_hand')->all())->toBe([10, 4])
        ->and(StockMovement::query()->where('location_id', $ctg->id)->sole()->quantity)->toBe(4);
});
