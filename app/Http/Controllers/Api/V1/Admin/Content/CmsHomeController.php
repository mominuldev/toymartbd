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
 * Homepage builder (Phase 7, Figma B · 26 Page builder 241:44102) — docs/api/cms-blocks.md.
 *
 * Every edit changes the DRAFT only; `publish` freezes the draft into the next immutable
 * version, which goes live and revalidates the storefront tag `content:home`. Reverting to an
 * old version publishes it again as a new version. Reads need content.view, writes content.manage.
 */
final class CmsHomeController extends ApiController
{
    use ManagesCmsLayout;

    /** `GET /content/home` — the draft, the live version and whether they differ. */
    public function show(LayoutDraft $draft): CmsHomeDraftResource
    {
        $this->authorize('viewAny', CmsBlock::class);

        return $this->layoutDraft(LayoutKey::home(), $draft);
    }

    /** `POST /content/home/blocks` — add a block (defaults when `content` is omitted). */
    public function storeBlock(StoreCmsBlockRequest $request, CreateBlock $create): JsonResponse
    {
        return $this->layoutStoreBlock(LayoutKey::home(), $request, $create);
    }

    /** `PATCH /content/home/blocks/{block}` */
    public function updateBlock(UpdateCmsBlockRequest $request, CmsBlock $block, UpdateBlock $update): CmsBlockResource
    {
        return $this->layoutUpdateBlock(LayoutKey::home(), $request, $block, $update);
    }

    /** `DELETE /content/home/blocks/{block}` — removes it from the draft; published versions keep their copy. */
    public function destroyBlock(CmsBlock $block, DeleteBlock $delete): Response
    {
        return $this->layoutDestroyBlock(LayoutKey::home(), $block, $delete);
    }

    /** `POST /content/home/blocks/{block}/duplicate` — a copy directly below. */
    public function duplicateBlock(Request $request, CmsBlock $block, DuplicateBlock $duplicate): JsonResponse
    {
        return $this->layoutDuplicateBlock(LayoutKey::home(), $request, $block, $duplicate);
    }

    /** `PUT /content/home/order` — `ids` must list every draft block once. */
    #[OpenApiResponse(422, 'The ids are not exactly the draft\'s blocks (`validation-error`)')]
    public function reorder(ReorderCmsBlocksRequest $request, ReorderBlocks $reorder, LayoutDraft $draft): CmsHomeDraftResource
    {
        return $this->layoutReorder(LayoutKey::home(), $request, $reorder, $draft);
    }

    /** `POST /content/home/publish` — the draft goes live as the next version. */
    #[OpenApiResponse(409, 'The draft has no unpublished changes (`conflict`)')]
    public function publish(PublishCmsLayoutRequest $request, PublishLayout $publish): JsonResponse
    {
        return $this->layoutPublish(LayoutKey::home(), $request, $publish);
    }

    /**
     * `GET /content/home/versions` — the 50 latest versions, newest first.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function versions(Request $request): array
    {
        return $this->layoutVersions(LayoutKey::home(), $request);
    }

    /**
     * `POST /content/home/versions/{version}/revert` — with `publish` (default) the old version
     * goes live as a new version and 201 returns it; with `publish: false` only the draft is
     * replaced and 200 returns the draft.
     */
    public function revert(RevertCmsLayoutRequest $request, CmsLayoutVersion $version, RevertLayout $revert, LayoutDraft $draft): JsonResponse|CmsHomeDraftResource
    {
        return $this->layoutRevert(LayoutKey::home(), $request, $version, $revert, $draft);
    }

    /**
     * `POST /content/home/preview-token` — a signed token, valid for 10 minutes, that lets the
     * storefront render the draft, and the storefront URL that opens it.
     */
    public function previewToken(IssueCmsPreviewTokenRequest $request): CmsPreviewTokenResource
    {
        return $this->layoutPreviewToken(LayoutKey::home(), $request, self::layoutUserId($request) ?? 0, '/api/preview/home');
    }
}
