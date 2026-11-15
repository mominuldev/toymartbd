<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Content\Concerns;

use App\Domain\Content\Blocks\Actions\CreateBlock;
use App\Domain\Content\Blocks\Actions\DeleteBlock;
use App\Domain\Content\Blocks\Actions\DuplicateBlock;
use App\Domain\Content\Blocks\Actions\PublishLayout;
use App\Domain\Content\Blocks\Actions\ReorderBlocks;
use App\Domain\Content\Blocks\Actions\RevertLayout;
use App\Domain\Content\Blocks\Actions\UpdateBlock;
use App\Domain\Content\Blocks\Exceptions\InvalidBlockOrder;
use App\Domain\Content\Blocks\Exceptions\NothingToPublish;
use App\Domain\Content\Blocks\Models\CmsBlock;
use App\Domain\Content\Blocks\Models\CmsLayoutVersion;
use App\Domain\Content\Blocks\Services\LayoutDraft;
use App\Domain\Content\Blocks\ValueObjects\LayoutKey;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The section-builder endpoints, shared by the homepage (CmsHomeController) and every static page
 * (CmsPageLayoutController): each works on one LayoutKey. Every edit changes the DRAFT only;
 * publish freezes it into the next immutable version. A block or version of another layout is a 404.
 */
trait ManagesCmsLayout
{
    /** Preview tokens are short-lived: long enough to look, too short to share. */
    public const int PREVIEW_TTL_SECONDS = 600;

    private function layoutDraft(LayoutKey $key, LayoutDraft $draft): CmsHomeDraftResource
    {
        $live = CmsLayoutVersion::latestFor($key)?->load('publisher');

        return new CmsHomeDraftResource([
            'placement' => $key->value,
            'blocks' => $draft->blocks($key),
            'live' => $live,
            'has_unpublished_changes' => $draft->hasUnpublishedChanges($key, $live),
        ]);
    }

    private function layoutStoreBlock(LayoutKey $key, StoreCmsBlockRequest $request, CreateBlock $create): JsonResponse
    {
        $block = $create->handle($key, $request->type(), $request->settings(), $request->position(), self::layoutUserId($request));

        return (new CmsBlockResource($block))->response()->setStatusCode(201);
    }

    private function layoutUpdateBlock(LayoutKey $key, UpdateCmsBlockRequest $request, CmsBlock $block, UpdateBlock $update): CmsBlockResource
    {
        self::assertBlockIn($key, $block);

        return new CmsBlockResource($update->handle($block, $request->settings(), self::layoutUserId($request)));
    }

    private function layoutDestroyBlock(LayoutKey $key, CmsBlock $block, DeleteBlock $delete): Response
    {
        $this->authorize('delete', $block);
        self::assertBlockIn($key, $block);
        $delete->handle($block);

        return response()->noContent();
    }

    private function layoutDuplicateBlock(LayoutKey $key, Request $request, CmsBlock $block, DuplicateBlock $duplicate): JsonResponse
    {
        $this->authorize('create', CmsBlock::class);
        self::assertBlockIn($key, $block);

        return (new CmsBlockResource($duplicate->handle($block, self::layoutUserId($request))))->response()->setStatusCode(201);
    }

    private function layoutReorder(LayoutKey $key, ReorderCmsBlocksRequest $request, ReorderBlocks $reorder, LayoutDraft $draft): CmsHomeDraftResource
    {
        try {
            $reorder->handle($key, $request->ids(), self::layoutUserId($request));
        } catch (InvalidBlockOrder $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return $this->layoutDraft($key, $draft);
    }

    private function layoutPublish(LayoutKey $key, PublishCmsLayoutRequest $request, PublishLayout $publish): JsonResponse
    {
        try {
            $version = $publish->handle($key, self::layoutUserId($request), $request->note());
        } catch (NothingToPublish $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return (new CmsLayoutVersionResource($version->load('publisher'), $version->version))->response()->setStatusCode(201);
    }

    /** @return array{data: list<array<string, mixed>>} */
    private function layoutVersions(LayoutKey $key, Request $request): array
    {
        $this->authorize('viewAny', CmsLayoutVersion::class);

        $versions = CmsLayoutVersion::query()->ofPlacement($key)->with('publisher')
            ->orderByDesc('version')->limit(50)->get();
        $live = $versions->first()?->version;

        return ['data' => array_values($versions->map(static fn (CmsLayoutVersion $v): array => (new CmsLayoutVersionResource($v, $live))->resolve($request))->all())];
    }

    private function layoutRevert(LayoutKey $key, RevertCmsLayoutRequest $request, CmsLayoutVersion $version, RevertLayout $revert, LayoutDraft $draft): JsonResponse|CmsHomeDraftResource
    {
        if (! $version->placement->equals($key)) {
            throw new NotFoundHttpException('No such version in this layout.');
        }

        $published = $revert->handle($version, self::layoutUserId($request), $request->publish());

        if ($published === null) {
            return $this->layoutDraft($key, $draft);
        }

        return (new CmsLayoutVersionResource($published->load('publisher'), $published->version))->response()->setStatusCode(201);
    }

    /** `$tokenId` is what the token unlocks: the page id for a page, the issuing user for the homepage. */
    private function layoutPreviewToken(LayoutKey $key, IssueCmsPreviewTokenRequest $request, int $tokenId, string $previewPath): CmsPreviewTokenResource
    {
        $token = PreviewToken::issue($key->previewTokenType(), $tokenId, self::PREVIEW_TTL_SECONDS);
        $base = config('services.storefront.url');
        $url = is_string($base) && $base !== ''
            ? rtrim($base, '/').$previewPath.'?'.http_build_query(['token' => $token, 'locale' => $request->previewLocale()])
            : null;

        return new CmsPreviewTokenResource([
            'token' => $token,
            'expires_at' => CarbonImmutable::now('UTC')->addSeconds(self::PREVIEW_TTL_SECONDS)->format('Y-m-d\TH:i:s\Z'),
            'url' => $url,
        ]);
    }

    private static function assertBlockIn(LayoutKey $key, CmsBlock $block): void
    {
        if (! $block->placement->equals($key)) {
            throw new NotFoundHttpException('No such block in this layout.');
        }
    }

    private static function layoutUserId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_int($id) ? $id : null;
    }
}
