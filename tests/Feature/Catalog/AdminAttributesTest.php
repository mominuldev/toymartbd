<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductVariant;
use App\Models\Enums\Role;
use App\Models\User;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Admin product attributes and values (P4-CAT-09): /api/v1/admin/attributes.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Manager)->create()->refresh(), 'web');
});

function useValueInVariant(ProductAttributeValue $value): void
{
    DB::table('variant_attribute_values')->insert([
        'variant_id' => ProductVariant::factory()->create()->id,
        'attribute_id' => $value->attribute_id,
        'attribute_value_id' => $value->id,
    ]);
}

it('creates an attribute with defaults and a generated slug', function (): void {
    $response = $this->postJson('/api/v1/admin/attributes', ['name' => 'Material', 'name_bn' => 'উপাদান'])->assertCreated();

    expect(array_keys($response->json()))->toBe(['id', 'name', 'name_bn', 'slug', 'type', 'is_variant_defining', 'is_filterable', 'position', 'values'])
        ->and($response->json('slug'))->toBe('material')
        ->and($response->json('type'))->toBe('select')
        ->and($response->json('is_variant_defining'))->toBeTrue()
        ->and($response->json('values'))->toBe([]);
});

it('rejects invalid attributes', function (array $body, string $field): void {
    ProductAttribute::factory()->create(['slug' => 'taken']);

    $response = $this->postJson('/api/v1/admin/attributes', $body)->assertUnprocessable();

    expect(collect($response->json('errors'))->pluck('field')->all())->toContain($field);
})->with([
    'missing name' => [['name_bn' => 'রং'], 'name'],
    'unknown type' => [['name' => 'X', 'type' => 'number'], 'type'],
    'duplicate slug' => [['name' => 'X', 'slug' => 'taken'], 'slug'],
]);

it('lists attributes by position with their values in order', function (): void {
    $this->seed(CatalogReferenceSeeder::class);

    $response = $this->getJson('/api/v1/admin/attributes')->assertOk();

    expect($response->json('data.*.slug'))->toBe(['color', 'size'])
        ->and($response->json('data.0.type'))->toBe('color')
        ->and($response->json('data.0.name_bn'))->toBe('রং')
        ->and($response->json('data.0.values.0'))->toMatchArray(['value' => 'Red', 'value_bn' => 'লাল', 'slug' => 'red', 'color_hex' => '#E53935', 'position' => 0])
        ->and($response->json('data.1.values.*.slug'))->toBe(['small', 'medium', 'large', 'extra-large']);
});

it('updates an attribute and clears swatches when it stops being a colour', function (): void {
    $attribute = ProductAttribute::factory()->color()->create();
    $value = ProductAttributeValue::factory()->for($attribute, 'attribute')->colored('#112233')->create();

    $this->patchJson("/api/v1/admin/attributes/{$attribute->id}", ['name' => 'Shade', 'is_filterable' => false])
        ->assertOk()->assertJsonPath('name', 'Shade')->assertJsonPath('is_filterable', false)->assertJsonPath('values.0.color_hex', '#112233');

    $this->patchJson("/api/v1/admin/attributes/{$attribute->id}", ['type' => 'select'])->assertOk()->assertJsonPath('values.0.color_hex', null);

    expect($value->refresh()->color_hex)->toBeNull();
});

it('adds values with slugs unique per attribute, keeping a swatch only for colour attributes', function (): void {
    $color = ProductAttribute::factory()->color()->create();
    $size = ProductAttribute::factory()->create(['type' => AttributeType::Select]);

    $this->postJson("/api/v1/admin/attributes/{$color->id}/values", ['value' => 'Red', 'value_bn' => 'লাল', 'color_hex' => '#e53935'])
        ->assertCreated()->assertJsonPath('slug', 'red')->assertJsonPath('color_hex', '#E53935')->assertJsonPath('position', 0);
    $this->postJson("/api/v1/admin/attributes/{$color->id}/values", ['value' => 'Red'])
        ->assertCreated()->assertJsonPath('slug', 'red-2')->assertJsonPath('position', 1);
    $this->postJson("/api/v1/admin/attributes/{$size->id}/values", ['value' => 'Red', 'color_hex' => '#E53935'])
        ->assertCreated()->assertJsonPath('slug', 'red')->assertJsonPath('color_hex', null);

    $this->postJson("/api/v1/admin/attributes/{$color->id}/values", ['value' => 'X', 'slug' => 'red'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/attributes/{$color->id}/values", ['value' => 'X', 'color_hex' => 'red'])->assertUnprocessable();
    $this->postJson("/api/v1/admin/attributes/{$color->id}/values", ['value' => '!!!'])->assertUnprocessable();
});

it('updates a value scoped to its attribute', function (): void {
    $value = ProductAttributeValue::factory()->create(['value' => 'Small']);
    $other = ProductAttribute::factory()->create();

    $this->patchJson("/api/v1/admin/attributes/{$value->attribute_id}/values/{$value->id}", ['value' => 'Tiny', 'value_bn' => 'খুব ছোট'])
        ->assertOk()->assertJsonPath('value', 'Tiny')->assertJsonPath('value_bn', 'খুব ছোট');

    $this->patchJson("/api/v1/admin/attributes/{$other->id}/values/{$value->id}", ['value' => 'Nope'])->assertNotFound();
});

it('reorders values and requires every id exactly once', function (): void {
    $attribute = ProductAttribute::factory()->create();
    $a = ProductAttributeValue::factory()->for($attribute, 'attribute')->create(['position' => 0]);
    $b = ProductAttributeValue::factory()->for($attribute, 'attribute')->create(['position' => 1]);
    $c = ProductAttributeValue::factory()->for($attribute, 'attribute')->create(['position' => 2]);
    $foreign = ProductAttributeValue::factory()->create();

    $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values/reorder", ['ids' => [$c->id, $a->id, $b->id]])
        ->assertOk()->assertJsonPath('values.*.id', [$c->id, $a->id, $b->id]);

    $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values/reorder", ['ids' => [$a->id, $b->id]])->assertUnprocessable();
    $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values/reorder", ['ids' => [$a->id, $b->id, $foreign->id]])->assertUnprocessable();
    $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values/reorder", ['ids' => [$a->id, $a->id, $b->id]])->assertUnprocessable();
});

it('deletes an unused value but refuses one a variant uses with a 409', function (): void {
    $attribute = ProductAttribute::factory()->create();
    $unused = ProductAttributeValue::factory()->for($attribute, 'attribute')->create();
    $used = ProductAttributeValue::factory()->for($attribute, 'attribute')->create();
    useValueInVariant($used);

    $this->deleteJson("/api/v1/admin/attributes/{$attribute->id}/values/{$unused->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/attributes/{$attribute->id}/values/{$used->id}")
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://toymartbd.com/problems/conflict')
        ->assertJsonPath('detail', 'This value is used by product variants and cannot be deleted.');

    expect(ProductAttributeValue::query()->pluck('id')->all())->toBe([$used->id]);
});

it('deletes an unused attribute with its values but refuses one in use with a 409', function (): void {
    $unused = ProductAttributeValue::factory()->create();
    $used = ProductAttributeValue::factory()->create();
    useValueInVariant($used);

    $this->deleteJson("/api/v1/admin/attributes/{$unused->attribute_id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/attributes/{$used->attribute_id}")->assertStatus(409);

    expect(ProductAttribute::query()->pluck('id')->all())->toBe([$used->attribute_id])
        ->and(ProductAttributeValue::query()->pluck('id')->all())->toBe([$used->id]);
});

it('seeds the reference attributes idempotently with Bangla names', function (): void {
    $this->seed(CatalogReferenceSeeder::class);
    ProductAttributeValue::factory()->for(ProductAttribute::query()->where('slug', 'size')->firstOrFail(), 'attribute')->create(['slug' => 'staff-added']);
    $this->seed(CatalogReferenceSeeder::class);

    expect(ProductAttribute::query()->count())->toBe(2)
        ->and(ProductAttributeValue::query()->whereNull('value_bn')->count())->toBe(0)
        ->and(ProductAttributeValue::query()->where('slug', 'staff-added')->exists())->toBeTrue()
        ->and(ProductAttributeValue::query()->whereHas('attribute', fn ($q) => $q->where('slug', 'color'))->whereNull('color_hex')->count())->toBe(0);
});
