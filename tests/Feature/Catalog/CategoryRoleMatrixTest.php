<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Category;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every admin categories endpoint (CLAUDE.md rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('guards GET /categories (flat and tree) with catalog.view', function (): void {
    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson('/api/v1/admin/categories'));
    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson('/api/v1/admin/categories?tree=1'));
});

it('guards POST /categories with catalog.create', function (): void {
    RoleMatrix::assert($this, Permission::CatalogCreate, fn () => $this->postJson('/api/v1/admin/categories', ['name' => 'Puzzles']), 201);

    $allowed = collect(Role::cases())->filter(fn (Role $role): bool => RoleMatrix::allows($role, Permission::CatalogCreate))->count();
    expect(Category::query()->count())->toBe($allowed);
});

it('guards GET /categories/{category} with catalog.view', function (): void {
    $category = Category::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson("/api/v1/admin/categories/{$category->id}"));
});

it('guards PATCH /categories/{category} with catalog.update', function (): void {
    $category = Category::factory()->create(['name' => 'Before']);

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->patchJson("/api/v1/admin/categories/{$category->id}", ['name' => 'After']));
});

it('guards POST /categories/{category}/move with catalog.update', function (): void {
    $category = Category::factory()->create();
    Category::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->postJson("/api/v1/admin/categories/{$category->id}/move", ['parent_id' => null, 'position' => 1]));
});

it('leaves a category untouched for a role without catalog.update', function (): void {
    $category = Category::factory()->create(['name' => 'Before']);
    $other = Category::factory()->create();

    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->patchJson("/api/v1/admin/categories/{$category->id}", ['name' => 'After']));
    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->postJson("/api/v1/admin/categories/{$category->id}/move", ['parent_id' => $other->id, 'position' => 0]));

    expect($category->refresh()->name)->toBe('Before')->and($category->parent_id)->toBeNull();
});

it('guards DELETE /categories/{category} with catalog.archive', function (): void {
    $category = Category::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogArchive, fn () => $this->deleteJson("/api/v1/admin/categories/{$category->id}"), 204);

    expect($category->refresh()->isActive())->toBeFalse();
});

it('refuses a guest with 401 on every admin categories endpoint', function (string $method, string $uri): void {
    $category = Category::factory()->create();

    $this->json($method, str_replace('{id}', (string) $category->id, $uri), ['name' => 'X', 'parent_id' => null, 'position' => 0])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with([
    ['GET', '/api/v1/admin/categories'],
    ['POST', '/api/v1/admin/categories'],
    ['GET', '/api/v1/admin/categories/{id}'],
    ['PATCH', '/api/v1/admin/categories/{id}'],
    ['POST', '/api/v1/admin/categories/{id}/move'],
    ['DELETE', '/api/v1/admin/categories/{id}'],
]);
