<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Domain\Content\Blocks\Actions\CreateBlock;
use App\Domain\Content\Blocks\Actions\DeleteBlock;
use App\Domain\Content\Blocks\Actions\DuplicateBlock;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Actions\ReorderBlocks;
use App\Domain\Content\Blocks\Actions\RevertLayout;
use App\Domain\Content\Blocks\Actions\UpdateBlock;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\LayoutDraft;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
use App\Domain\Content\Models\CmsPage;
use App\Http\Controllers\Api\V1\Admin\Content\Concerns\ManagesCmsLayout;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Content\IssueCmsPreviewTokenRequest;
use App\Http\Requests\Admin\Content\PublishCmsLayoutRequest;
use App\Http\Requests\Admin\Content\ReorderCmsBlocksRequest;
use App\Http\Requests\Admin\Content\RevertCmsLayoutRequest;
use App\Http\Requests\Admin\Content\StoreCmsBlockRequest;
use App\Http\Requests\Admin\Content\UpdateCmsBlockRequest;
use App\Http\Resources\Admin\Content\CmsBlockResource;
use App\Http\Resources\Admin\Content\CmsHomeDraftResource;
use App\Http\Resources\Admin\Content\CmsPreviewTokenResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A static page's section builder — the homepage builder (B · 26 Page builder 241:44102) for
 * one page; docs/api/cms-blocks.md. Same draft / publish / version rules as the homepage; a
 * publish revalidates `content:pages` + `content:page:{slug}`. The page's own status still
 * decides whether its URL is live. Reads need content.view, writes content.manage.
 */
final class CmsPageLayoutController extends ApiController
{
    use ManagesCmsLayout;

    /** `GET /content/pages/{page}/layout` — the draft, the live version and whether they differ. */
    public function show(CmsPage $page, LayoutDraft $draft): CmsHomeDraftResource
    {
        $this->authorize('viewAny', CmsBlock::class);

        return $this->layoutDraft(LayoutKey::page($page->id), $draft);
    }

    /** `POST /content/pages/{page}/layout/blocks` — add a block (defaults when `content` is omitted). */
    public function storeBlock(CmsPage $page, StoreCmsBlockRequest $request, CreateBlock $create): JsonResponse
    {
        return $this->layoutStoreBlock(LayoutKey::page($page->id), $request, $create);
    }

    /** `PATCH /content/pages/{page}/layout/blocks/{block}` */
    public function updateBlock(CmsPage $page, UpdateCmsBlockRequest $request, CmsBlock $block, UpdateBlock $update): CmsBlockResource
    {
        return $this->layoutUpdateBlock(LayoutKey::page($page->id), $request, $block, $update);
    }

    /** `DELETE /content/pages/{page}/layout/blocks/{block}` — removes it from the draft; published versions keep their copy. */
    public function destroyBlock(CmsPage $page, CmsBlock $block, DeleteBlock $delete): Response
    {
        return $this->layoutDestroyBlock(LayoutKey::page($page->id), $block, $delete);
    }

    /** `POST /content/pages/{page}/layout/blocks/{block}/duplicate` — a copy directly below. */
    public function duplicateBlock(CmsPage $page, Request $request, CmsBlock $block, DuplicateBlock $duplicate): JsonResponse
    {
        return $this->layoutDuplicateBlock(LayoutKey::page($page->id), $request, $block, $duplicate);
    }

    /** `PUT /content/pages/{page}/layout/order` — `ids` must list every draft block once. */
    #[OpenApiResponse(422, 'The ids are not exactly the draft\'s blocks (`validation-error`)')]
    public function reorder(CmsPage $page, ReorderCmsBlocksRequest $request, ReorderBlocks $reorder, LayoutDraft $draft): CmsHomeDraftResource
    {
        return $this->layoutReorder(LayoutKey::page($page->id), $request, $reorder, $draft);
    }

    /** `POST /content/pages/{page}/layout/publish` — the draft goes live as the next version. */
    #[OpenApiResponse(409, 'The draft has no unpublished changes (`conflict`)')]
    public function publish(CmsPage $page, PublishCmsLayoutRequest $request, PublishLayout $publish): JsonResponse
    {
        return $this->layoutPublish(LayoutKey::page($page->id), $request, $publish);
    }

    /**
     * `GET /content/pages/{page}/layout/versions` — the 50 latest versions, newest first.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function versions(CmsPage $page, Request $request): array
    {
        return $this->layoutVersions(LayoutKey::page($page->id), $request);
    }

    /**
     * `POST /content/pages/{page}/layout/versions/{version}/revert` — with `publish` (default) the old version
     * goes live as a new version and 201 returns it; with `publish: false` only the draft is
     * replaced and 200 returns the draft.
     */
    public function revert(CmsPage $page, RevertCmsLayoutRequest $request, CmsLayoutVersion $version, RevertLayout $revert, LayoutDraft $draft): JsonResponse|CmsHomeDraftResource
    {
        return $this->layoutRevert(LayoutKey::page($page->id), $request, $version, $revert, $draft);
    }

    /**
     * `POST /content/pages/{page}/layout/preview-token` — a signed token, valid for 10 minutes, that lets the
     * storefront render the draft, and the storefront URL that opens it.
     */
    public function previewToken(CmsPage $page, IssueCmsPreviewTokenRequest $request): CmsPreviewTokenResource
    {
        return $this->layoutPreviewToken(LayoutKey::page($page->id), $request, $page->id, '/api/preview/page');
    }
}
