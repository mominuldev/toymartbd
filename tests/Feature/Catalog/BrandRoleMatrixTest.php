<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Brand;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every admin brands endpoint (CLAUDE.md rule 21).
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

/*
 * RoleMatrix::assert sends one request per role inside a single test (one app boot per
 * endpoint rather than seven), which keeps the suite's memory in check.
 */

it('guards GET /brands with catalog.view', function (): void {
    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson('/api/v1/admin/brands'));
});

it('guards POST /brands with catalog.create', function (): void {
    RoleMatrix::assert($this, Permission::CatalogCreate, fn () => $this->postJson('/api/v1/admin/brands', ['name' => 'LEGO']), 201);

    $allowed = collect(Role::cases())->filter(fn (Role $role): bool => RoleMatrix::allows($role, Permission::CatalogCreate))->count();
    expect(Brand::query()->count())->toBe($allowed);
});

it('guards GET /brands/{brand} with catalog.view', function (): void {
    $brand = Brand::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson("/api/v1/admin/brands/{$brand->id}"));
});

it('guards PATCH /brands/{brand} with catalog.update', function (): void {
    $brand = Brand::factory()->create(['name' => 'Before']);

    RoleMatrix::assert($this, Permission::CatalogUpdate, fn () => $this->patchJson("/api/v1/admin/brands/{$brand->id}", ['name' => 'After']));
});

it('leaves a brand untouched for a role without catalog.update', function (): void {
    $brand = Brand::factory()->create(['name' => 'Before']);

    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->patchJson("/api/v1/admin/brands/{$brand->id}", ['name' => 'After']));

    expect($brand->refresh()->name)->toBe('Before');
});

it('guards DELETE /brands/{brand} with catalog.archive', function (): void {
    $brand = Brand::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogArchive, fn () => $this->deleteJson("/api/v1/admin/brands/{$brand->id}"), 204);

    expect($brand->refresh()->isActive())->toBeFalse();
});

it('leaves a brand active for a role without catalog.archive', function (): void {
    $brand = Brand::factory()->create();

    RoleMatrix::assertFor($this, Role::Viewer, false, fn () => $this->deleteJson("/api/v1/admin/brands/{$brand->id}"));

    expect($brand->refresh()->isActive())->toBeTrue();
});

it('refuses a guest with 401 on every admin brands endpoint', function (string $method, string $uri): void {
    $brand = Brand::factory()->create();

    $this->json($method, str_replace('{id}', (string) $brand->id, $uri), ['name' => 'X'])
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://toymartbd.com/problems/unauthenticated');
})->with([
    ['GET', '/api/v1/admin/brands'],
    ['POST', '/api/v1/admin/brands'],
    ['GET', '/api/v1/admin/brands/{id}'],
    ['PATCH', '/api/v1/admin/brands/{id}'],
    ['DELETE', '/api/v1/admin/brands/{id}'],
]);
