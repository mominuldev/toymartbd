<?php

declare(strict_types=1);

use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Models\CmsPage;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Content\CmsBlocks\CmsKit;
use Tests\Support\RoleMatrix;

/*
 * 403 role matrix for every page section-builder endpoint (CLAUDE.md rule 21): content.view reads
 * and previews, content.manage changes and publishes — the same split as the homepage builder.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->page = CmsPage::query()->create(['slug' => 'about-'.uniqid(), 'title' => 'About', 'status' => 'draft']);
    $this->base = '/api/v1/admin/content/pages/'.$this->page->id.'/layout';
});

function plrBlock(CmsPage $page): CmsBlock
{
    return CmsKit::block('faq', [], ['placement' => LayoutKey::page($page->id)]);
}

it('guards GET /content/pages/{page}/layout with content.view', function (): void {
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson($this->base));
});

it('guards GET /content/pages/{page}/layout/versions with content.view', function (): void {
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->getJson($this->base.'/versions'));
});

it('guards POST /content/pages/{page}/layout/preview-token with content.view', function (): void {
    RoleMatrix::assert($this, Permission::ContentView, fn () => $this->postJson($this->base.'/preview-token', ['locale' => 'en']));
});

it('guards POST /content/pages/{page}/layout/blocks with content.manage', function (): void {
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson($this->base.'/blocks', ['type' => 'faq']), 201);
});

it('guards PATCH /content/pages/{page}/layout/blocks/{block} with content.manage', function (): void {
    $block = plrBlock($this->page);
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->patchJson($this->base.'/blocks/'.$block->id, ['label' => 'x']));
});

it('guards POST /content/pages/{page}/layout/blocks/{block}/duplicate with content.manage', function (): void {
    $block = plrBlock($this->page);
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson($this->base.'/blocks/'.$block->id.'/duplicate'), 201);
});

it('guards PUT /content/pages/{page}/layout/order with content.manage', function (): void {
    plrBlock($this->page);
    plrBlock($this->page);
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->putJson($this->base.'/order', [
        'ids' => CmsBlock::query()->where('placement', 'page:'.$this->page->id)->orderByDesc('id')->pluck('id')->all(),
    ]));
});

it('guards DELETE /content/pages/{page}/layout/blocks/{block} with content.manage', function (Role $role, bool $allowed): void {
    $block = plrBlock($this->page);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->deleteJson($this->base.'/blocks/'.$block->id), 204);
})->with(RoleMatrix::dataset(Permission::ContentManage));

it('guards POST /content/pages/{page}/layout/publish with content.manage', function (Role $role, bool $allowed): void {
    plrBlock($this->page);
    RoleMatrix::assertFor($this, $role, $allowed, fn () => $this->postJson($this->base.'/publish'), 201);
})->with(RoleMatrix::dataset(Permission::ContentManage));

it('guards POST /content/pages/{page}/layout/versions/{version}/revert with content.manage', function (): void {
    plrBlock($this->page);
    $version = app(PublishLayout::class)->handle(LayoutKey::page($this->page->id), null);
    RoleMatrix::assert($this, Permission::ContentManage, fn () => $this->postJson($this->base.'/versions/'.$version->id.'/revert', ['publish' => false]));
});
