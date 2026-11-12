<?php

declare(strict_types=1);

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Domain\Catalog\Models\Product;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Media\MediaKit;
use Tests\Support\ImageFixtures;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every media-library endpoint (CLAUDE.md rule 21). View needs
 * catalog.view or content.view (every launch role holds catalog.view, so the
 * CatalogView matrix is the same set); writes need media.manage; delete, bulk delete
 * and bulk download need media.delete.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Queue::fake(); // renditions are not the point here
    Storage::fake('public');
    Storage::fake('local');
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('gives media.delete to super_admin and manager only, media.manage also to content_editor', function (): void {
    $holders = fn (Permission $p): array => collect(Role::cases())->filter(fn (Role $r): bool => RoleMatrix::allows($r, $p))->map->value->values()->all();

    expect($holders(Permission::MediaDelete))->toBe(['super_admin', 'manager'])
        ->and($holders(Permission::MediaManage))->toBe(['super_admin', 'manager', 'content_editor'])
        ->and($holders(Permission::CatalogView))->toBe(array_map(fn (Role $r): string => $r->value, Role::cases()));
});

it('guards the read endpoints with catalog.view or content.view', function (string $uri): void {
    $media = Media::factory()->create();
    Storage::disk('local')->put($media->originalPath(), 'x');

    RoleMatrix::assert($this, Permission::CatalogView, fn () => $this->getJson(str_replace('{id}', (string) $media->id, $uri)));
})->with([
    'GET /media' => '/api/v1/admin/media',
    'GET /media/counts' => '/api/v1/admin/media/counts',
    'GET /media/storage' => '/api/v1/admin/media/storage',
    'GET /media/folders' => '/api/v1/admin/media/folders',
    'GET /media/{id}' => '/api/v1/admin/media/{id}',
    'GET /media/{id}/download' => '/api/v1/admin/media/{id}/download',
]);

it('guards POST /media with media.manage', function (): void {
    RoleMatrix::assert($this, Permission::MediaManage, fn () => $this->post('/api/v1/admin/media', ['file' => MediaKit::image()], ['Accept' => 'application/json']), 201);
});

it('guards PATCH /media/{id} with media.manage', function (): void {
    $media = Media::factory()->create();

    RoleMatrix::assert($this, Permission::MediaManage, fn () => $this->patchJson("/api/v1/admin/media/{$media->id}", ['caption' => 'c '.uniqid()]));
});

it('guards POST /media/{id}/replace with media.manage', function (): void {
    $media = Media::factory()->create();

    RoleMatrix::assert($this, Permission::MediaManage, fn () => $this->post("/api/v1/admin/media/{$media->id}/replace", ['file' => MediaKit::image()], ['Accept' => 'application/json']));
});

it('guards POST /media/bulk/move with media.manage', function (): void {
    $media = Media::factory()->create();

    RoleMatrix::assert($this, Permission::MediaManage, fn () => $this->postJson('/api/v1/admin/media/bulk/move', ['ids' => [$media->id], 'folder_id' => null]));
});

it('guards folder writes with media.manage', function (): void {
    RoleMatrix::assert($this, Permission::MediaManage, fn () => $this->postJson('/api/v1/admin/media/folders', ['name' => 'Folder '.uniqid()]), 201);

    $folder = MediaFolder::query()->create(['name' => 'Rename me', 'name_key' => 'rename me']);
    RoleMatrix::assert($this, Permission::MediaManage, fn () => $this->patchJson("/api/v1/admin/media/folders/{$folder->id}", ['name' => 'Renamed '.uniqid()]));

    RoleMatrix::assert($this, Permission::MediaManage, function () {
        $folder = MediaFolder::query()->create(['name' => 'Gone '.uniqid(), 'name_key' => 'gone '.uniqid()]);

        return $this->deleteJson("/api/v1/admin/media/folders/{$folder->id}");
    }, 204);
});

it('guards DELETE /media/{id} with media.delete', function (): void {
    RoleMatrix::assert($this, Permission::MediaDelete, function () {
        $media = Media::factory()->create();

        return $this->deleteJson("/api/v1/admin/media/{$media->id}");
    }, 204);
});

it('guards POST /media/bulk/delete with media.delete', function (): void {
    RoleMatrix::assert($this, Permission::MediaDelete, function () {
        $media = Media::factory()->create();

        return $this->postJson('/api/v1/admin/media/bulk/delete', ['ids' => [$media->id]]);
    });
});

it('guards POST /media/bulk/download with media.delete', function (): void {
    $media = Media::factory()->create();
    Storage::disk('local')->put($media->originalPath(), ImageFixtures::bytes('webp', 10, 10));

    RoleMatrix::assert($this, Permission::MediaDelete, fn () => $this->postJson('/api/v1/admin/media/bulk/download', ['ids' => [$media->id]]));
});

it('guards attaching a library image to a product with catalog.update', function (): void {
    $product = Product::factory()->create();

    RoleMatrix::assert($this, Permission::CatalogUpdate, function () use ($product) {
        $media = Media::factory()->create();

        return $this->postJson("/api/v1/admin/products/{$product->id}/images", ['media_id' => $media->id]);
    }, 201);
});

it('refuses an unauthenticated request and needs no auth for the public redirect', function (): void {
    $media = Media::factory()->create();

    $this->getJson('/api/v1/admin/media')->assertStatus(401);
    $this->get("/m/{$media->id}/x.webp")->assertStatus(302);
});
