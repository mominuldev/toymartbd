<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every admin attribute and attribute-value endpoint (rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('guards GET /attributes with catalog.view', function (): void {
    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson('/api/v1/admin/attributes'));
});

it('guards GET /attributes/{attribute} with catalog.view', function (): void {
    $attribute = ProductAttribute::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson("/api/v1/admin/attributes/{$attribute->id}"));
});

it('guards POST /attributes with catalog.create', function (): void {
    RoleMatrix::assert($this, Permission::CatalogCreate, fn () => $this->postJson('/api/v1/admin/attributes', ['name' => 'Material']), 201);
});

it('guards PATCH /attributes/{attribute} with catalog.update', function (): void {
    $attribute = ProductAttribute::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->patchJson("/api/v1/admin/attributes/{$attribute->id}", ['name' => 'After']));
});

it('guards DELETE /attributes/{attribute} with catalog.archive', function (): void {
    RoleMatrix::assert($this, Permission::CatalogArchive, function () {
        $attribute = ProductAttribute::factory()->create();

        return $this->deleteJson("/api/v1/admin/attributes/{$attribute->id}");
    }, 204);
});

it('guards POST /attributes/{attribute}/values with catalog.create', function (): void {
    $attribute = ProductAttribute::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogCreate, fn () => $this->postJson("/api/v1/admin/attributes/{$attribute->id}/values", ['value' => 'Wood']), 201);
});

it('guards PATCH /attributes/{attribute}/values/{value} with catalog.update', function (): void {
    $value = ProductAttributeValue::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->patchJson("/api/v1/admin/attributes/{$value->attribute_id}/values/{$value->id}", ['value' => 'After']));
});

it('guards POST /attributes/{attribute}/values/reorder with catalog.update', function (): void {
    $value = ProductAttributeValue::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->postJson("/api/v1/admin/attributes/{$value->attribute_id}/values/reorder", ['ids' => [$value->id]]));
});

it('guards DELETE /attributes/{attribute}/values/{value} with catalog.archive', function (): void {
    $attribute = ProductAttribute::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogArchive, function () use ($attribute) {
        $value = ProductAttributeValue::factory()->for($attribute, 'attribute')->create();

        return $this->deleteJson("/api/v1/admin/attributes/{$attribute->id}/values/{$value->id}");
    }, 204);
});

it('leaves an attribute and its values intact for a viewer', function (): void {
    $value = ProductAttributeValue::factory()->create(['value' => 'Before']);

    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->deleteJson("/api/v1/admin/attributes/{$value->attribute_id}"));
    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->patchJson("/api/v1/admin/attributes/{$value->attribute_id}/values/{$value->id}", ['value' => 'After']));

    expect($value->refresh()->value)->toBe('Before');
});

it('refuses a guest with 401 on every admin attribute endpoint', function (string $method, string $uri): void {
    $value = ProductAttributeValue::factory()->create();
    $uri = str_replace(['{a}', '{v}'], [(string) $value->attribute_id, (string) $value->id], $uri);

    $this->json($method, $uri, ['name' => 'X', 'value' => 'X', 'ids' => [$value->id]])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with([
    ['GET', '/api/v1/admin/attributes'],
    ['POST', '/api/v1/admin/attributes'],
    ['GET', '/api/v1/admin/attributes/{a}'],
    ['PATCH', '/api/v1/admin/attributes/{a}'],
    ['DELETE', '/api/v1/admin/attributes/{a}'],
    ['POST', '/api/v1/admin/attributes/{a}/values'],
    ['POST', '/api/v1/admin/attributes/{a}/values/reorder'],
    ['PATCH', '/api/v1/admin/attributes/{a}/values/{v}'],
    ['DELETE', '/api/v1/admin/attributes/{a}/values/{v}'],
]);
