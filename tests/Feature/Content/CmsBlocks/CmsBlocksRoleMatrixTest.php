<?php

declare(strict_types=1);

use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every homepage-builder endpoint (CLAUDE.md rule 21): content.view reads
 * and previews, content.manage changes and publishes.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
});

it('guards GET /content/home with content.view', function (): void {
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson(CmsKit::ADMIN));
});

it('guards GET /content/home/versions with content.view', function (): void {
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson(CmsKit::ADMIN.'/versions'));
});

it('guards POST /content/home/preview-token with content.view', function (): void {
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->postJson(CmsKit::ADMIN.'/preview-token', ['locale' => 'en']));
});

it('guards POST /content/home/blocks with content.manage', function (): void {
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson(CmsKit::ADMIN.'/blocks', ['type' => 'faq']), 201);
});

it('guards PATCH /content/home/blocks/{block} with content.manage', function (): void {
    $block = CmsKit::block('faq');
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->patchJson(CmsKit::ADMIN.'/blocks/'.$block->id, ['label' => 'x']));
});

it('guards POST /content/home/blocks/{block}/duplicate with content.manage', function (): void {
    $block = CmsKit::block('faq');
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson(CmsKit::ADMIN.'/blocks/'.$block->id.'/duplicate'), 201);
});

it('guards PUT /content/home/order with content.manage', function (): void {
    CmsKit::block('faq');
    CmsKit::block('usp_band');
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->putJson(CmsKit::ADMIN.'/order', [
        'ids' => CmsBlock::query()->orderByDesc('position')->pluck('id')->all(),
    ]));
});

it('guards DELETE /content/home/blocks/{block} with content.manage', function (Role $role, bool $allowed): void {
    $block = CmsKit::block('faq');
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson(CmsKit::ADMIN.'/blocks/'.$block->id), 204);
})->with(RoleMatrix::dataset(Permission::ContentManage));

it('guards POST /content/home/publish with content.manage', function (Role $role, bool $allowed): void {
    CmsKit::block('faq');
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson(CmsKit::ADMIN.'/publish'), 201);
})->with(RoleMatrix::dataset(Permission::ContentManage));

it('guards POST /content/home/versions/{version}/revert with content.manage', function (): void {
    CmsKit::block('faq');
    $version = app(PublishLayout::class)->handle(BlockPlacement::Home, null);
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson(CmsKit::ADMIN.'/versions/'.$version->id.'/revert', ['publish' => false]));
});
