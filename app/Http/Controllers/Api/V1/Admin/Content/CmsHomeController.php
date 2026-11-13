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
use App\Domain\Content\Blocks\Enums\BlockPlacement;
use App\Domain\Content\Blocks\Exceptions\InvalidBlockOrder;
use App\Domain\Content\Blocks\Exceptions\NothingToPublish;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\LayoutDraft;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Content\IssueCmsPreviewTokenRequest;
use App\Http\Requests\Admin\Content\PublishCmsLayoutRequest;
use App\Http\Requests\Admin\Content\ReorderCmsBlocksRequest;
use App\Http\Requests\Admin\Content\RevertCmsLayoutRequest;
use App\Http\Requests\Admin\Content\StoreCmsBlockRequest;
use App\Http\Requests\Admin\Content\UpdateCmsBlockRequest;
use App\Http\Resources\Admin\Content\CmsBlockResource;
use App\Http\Resources\Admin\Content\CmsHomeDraftResource;
use App\Http\Resources\Admin\Content\CmsLayoutVersionResource;
use App\Http\Resources\Admin\Content\CmsPreviewTokenResource;
use App\Support\PreviewToken;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Homepage builder (Phase 7, Figma B · 26 Page builder 241:44102) — docs/api/cms-blocks.md.
 *
 * Every edit changes the DRAFT only; `publish` freezes the draft into the next immutable
 * version, which goes live and revalidates the storefront tag `content:home`. Reverting to an
 * old version publishes it again as a new version. Reads need content.view, writes content.manage.
 */
final class CmsHomeController extends ApiController
{
    /** Preview tokens are short-lived: long enough to look, too short to share. */
    public const int PREVIEW_TTL_SECONDS = 600;

    /** `GET /content/home` — the draft, the live version and whether they differ. */
    public function show(LayoutDraft $draft): CmsHomeDraftResource
    {
        $this->authorize('viewAny', CmsBlock::class);

        return $this->draftResource($draft);
    }

    /** `POST /content/home/blocks` — add a block (defaults when `content` is omitted). */
    public function storeBlock(StoreCmsBlockRequest $request, CreateBlock $create): JsonResponse
    {
        $block = $create->handle(BlockPlacement::Home, $request->type(), $request->settings(), $request->position(), self::userId($request));

        return (new CmsBlockResource($block))->response()->setStatusCode(201);
    }

    /** `PATCH /content/home/blocks/{block}` */
    public function updateBlock(UpdateCmsBlockRequest $request, CmsBlock $block, UpdateBlock $update): CmsBlockResource
    {
        return new CmsBlockResource($update->handle($block, $request->settings(), self::userId($request)));
    }

    /** `DELETE /content/home/blocks/{block}` — removes it from the draft; published versions keep their copy. */
    public function destroyBlock(CmsBlock $block, DeleteBlock $delete): Response
    {
        $this->authorize('delete', $block);
        $delete->handle($block);

        return response()->noContent();
    }

    /** `POST /content/home/blocks/{block}/duplicate` — a copy directly below. */
    public function duplicateBlock(Request $request, CmsBlock $block, DuplicateBlock $duplicate): JsonResponse
    {
        $this->authorize('create', CmsBlock::class);

        return (new CmsBlockResource($duplicate->handle($block, self::userId($request))))->response()->setStatusCode(201);
    }

    /** `PUT /content/home/order` — `ids` must list every draft block once. */
    #[OpenApiResponse(422, 'The ids are not exactly the draft\'s blocks (`validation-error`)')]
    public function reorder(ReorderCmsBlocksRequest $request, ReorderBlocks $reorder, LayoutDraft $draft): CmsHomeDraftResource
    {
        try {
            $reorder->handle(BlockPlacement::Home, $request->ids(), self::userId($request));
        } catch (InvalidBlockOrder $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->draftResource($draft);
    }

    /** `POST /content/home/publish` — the draft goes live as the next version. */
    #[OpenApiResponse(409, 'The draft has no unpublished changes (`conflict`)')]
    public function publish(PublishCmsLayoutRequest $request, PublishLayout $publish): JsonResponse
    {
        try {
            $version = $publish->handle(BlockPlacement::Home, self::userId($request), $request->note());
        } catch (NothingToPublish $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return (new CmsLayoutVersionResource($version->load('publisher'), $version->version))->response()->setStatusCode(201);
    }

    /**
     * `GET /content/home/versions` — the 50 latest versions, newest first.
     *
     * @return array{data: list<array<string, mixed>>}
     */
    public function versions(Request $request): array
    {
        $this->authorize('viewAny', CmsLayoutVersion::class);

        $versions = CmsLayoutVersion::query()->ofPlacement(BlockPlacement::Home)->with('publisher')
            ->orderByDesc('version')->limit(50)->get();
        $live = $versions->first()?->version;

        return ['data' => array_values($versions->map(static fn (CmsLayoutVersion $v): array => (new CmsLayoutVersionResource($v, $live))->resolve($request))->all())];
    }

    /**
     * `POST /content/home/versions/{version}/revert` — with `publish` (default) the old version
     * goes live as a new version and 201 returns it; with `publish: false` only the draft is
     * replaced and 200 returns the draft.
     */
    public function revert(RevertCmsLayoutRequest $request, CmsLayoutVersion $version, RevertLayout $revert, LayoutDraft $draft): JsonResponse|CmsHomeDraftResource
    {
        $published = $revert->handle($version, self::userId($request), $request->publish());

        if ($published === null) {
            return $this->draftResource($draft);
        }

        return (new CmsLayoutVersionResource($published->load('publisher'), $published->version))->response()->setStatusCode(201);
    }

    /**
     * `POST /content/home/preview-token` — a signed token, valid for 10 minutes, that lets the
     * storefront render the draft, and the storefront URL that opens it.
     */
    public function previewToken(IssueCmsPreviewTokenRequest $request): CmsPreviewTokenResource
    {
        $token = PreviewToken::issue(BlockPlacement::Home->previewTokenType(), self::userId($request) ?? 0, self::PREVIEW_TTL_SECONDS);
        $base = config('services.storefront.url');
        $url = is_string($base) && $base !== ''
            ? rtrim($base, '/').'/api/preview/home?'.http_build_query(['token' => $token, 'locale' => $request->previewLocale()])
            : null;

        return new CmsPreviewTokenResource([
            'token' => $token,
            'expires_at' => CarbonImmutable::now('UTC')->addSeconds(self::PREVIEW_TTL_SECONDS)->format('Y-m-d\TH:i:s\Z'),
            'url' => $url,
        ]);
    }

    private function draftResource(LayoutDraft $draft): CmsHomeDraftResource
    {
        $live = CmsLayoutVersion::latestFor(BlockPlacement::Home)?->load('publisher');

        return new CmsHomeDraftResource([
            'blocks' => $draft->blocks(BlockPlacement::Home),
            'live' => $live,
            'has_unpublished_changes' => $draft->hasUnpublishedChanges(BlockPlacement::Home, $live),
        ]);
    }

    private static function userId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
