<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Media\Actions\DeleteMedia;
use App\Domain\Catalog\Media\Actions\ReplaceMedia;
use App\Domain\Catalog\Media\Actions\UpdateMedia;
use App\Domain\Catalog\Media\Actions\UploadMedia;
use App\Domain\Catalog\Media\Exceptions\MediaInUse;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaListing;
use App\Domain\Catalog\Media\Services\MediaUsages;
use App\Domain\Catalog\Services\MediaUrls;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Problems\ProblemDetails;
use App\Http\Problems\ProblemType;
use App\Http\Requests\Admin\Media\MediaListQuery;
use App\Http\Requests\Admin\Media\ReplaceMediaRequest;
use App\Http\Requests\Admin\Media\StoreMediaRequest;
use App\Http\Requests\Admin\Media\UpdateMediaRequest;
use App\Http\Resources\Admin\MediaDetailResource;
use App\Http\Resources\Admin\MediaResource;
use App\Http\Resources\Admin\MediaUsageResource;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The media library (docs/api/media-library.md). Reads need catalog.view or
 * content.view; upload, edit and replace need media.manage; delete needs media.delete.
 */
final class MediaController extends ApiController
{
    public function __construct(
        private readonly MediaListing $listing,
        private readonly MediaUsages $usages,
    ) {}

    /**
     * `GET /media` — cursor-paginated; `meta.total` counts the matches under the filters.
     * Anything outside the allowlist is a 400.
     */
    #[QueryParameter('filter[folder]', 'A folder id, or `none` for files in no folder.', type: 'string')]
    #[QueryParameter('filter[unused]', '`1`: only files used nowhere (the Unused view).', type: 'string')]
    #[QueryParameter('filter[kind]', 'image|document', type: "'image'|'document'")]
    #[QueryParameter('filter[missing_alt]', 'bn|en — images missing that language\'s alt text.', type: "'bn'|'en'")]
    #[QueryParameter('filter[search]', 'Filename, alt text (EN or BN), or the name of a product using the file.', type: 'string')]
    #[QueryParameter('sort', 'newest (default) · oldest · name (filename A→Z) · size (largest first).', type: "'newest'|'oldest'|'name'|'size'", default: 'newest')]
    #[QueryParameter('per_page', '1–100, default 30.', type: 'int')]
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` / `meta.prev_cursor`.', type: 'string')]
    #[OpenApiResponse(400, 'Unlisted filter or sort, or an invalid filter value (`bad-request`)')]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Media::class);

        $q = MediaListQuery::from($request);
        $query = $this->listing->query($q->folder, $q->unused, $q->kind, $q->missingAlt, $q->search);
        $total = (clone $query)->count();

        $page = $this->listing->ordered($query, $q->sort)
            ->with(MediaResource::RELATIONS)
            ->cursorPaginate($q->perPage)
            ->withQueryString();

        /** @var list<Media> $items */
        $items = $page->items();
        $counts = $this->usages->counts(array_map(static fn (Media $m): int => $m->id, $items));

        foreach ($items as $media) {
            $media->usedInCount = $counts[$media->id] ?? 0;
        }

        return MediaResource::collection($page)->additional(['meta' => ['total' => $total]]);
    }

    /**
     * `GET /media/counts` — every number in the rail, unfiltered.
     *
     * @return array{data: array{all: int, unused: int, unfiled: int, folders: list<array{id: int, count: int}>, kinds: array{image: int, document: int}, missing_alt: array{bn: int, en: int}}}
     */
    public function counts(): array
    {
        $this->authorize('viewAny', Media::class);

        return ['data' => $this->listing->counts()];
    }

    /**
     * `GET /media/storage` — the storage meter. `quota_bytes` is null without MEDIA_STORAGE_QUOTA_GB.
     *
     * @return array{data: array{used_bytes: int, originals_bytes: int, renditions_bytes: int, quota_bytes: int|null, file_count: int}}
     */
    public function storage(): array
    {
        $this->authorize('viewAny', Media::class);

        return ['data' => $this->listing->storage()];
    }

    /** `GET /media/{media}` — with `used_in` and the renditions. */
    public function show(Media $media): MediaDetailResource
    {
        $this->authorize('view', $media);

        return $this->detail($media);
    }

    /**
     * `POST /media` — `multipart/form-data`, one file. 201; an image is `processing`
     * until its renditions are written after commit.
     */
    #[OpenApiResponse(422, 'Not a JPEG/PNG/WebP/AVIF/PDF by content, over 10 MB or 8000 px, an embedded payload, a PDF with active content, or undecodable (`validation-error` on `file`)')]
    public function store(StoreMediaRequest $request, UploadMedia $upload): JsonResponse
    {
        $media = $upload->handle($request->uploadedPath(), $request->clientName(), $request->attributesForUpload(), $request->user()?->getAuthIdentifier() !== null ? (int) $request->user()->getAuthIdentifier() : null);
        $media->load(MediaResource::RELATIONS);
        $media->usedInCount = 0;

        return (new MediaResource($media))->response()->setStatusCode(201);
    }

    /** `PATCH /media/{media}` — alt text (shared with every product using the file), caption, folder. */
    public function update(UpdateMediaRequest $request, Media $media, UpdateMedia $updateMedia): MediaDetailResource
    {
        return $this->detail($updateMedia->handle($media, $request->changes()));
    }

    /** `POST /media/{media}/replace` — same id, new file of the same kind; old files deleted after commit. */
    #[OpenApiResponse(422, 'Refused file, or not the same kind as the media (`validation-error` on `file`)')]
    public function replace(ReplaceMediaRequest $request, Media $media, ReplaceMedia $replaceMedia): MediaDetailResource
    {
        return $this->detail($replaceMedia->handle($media, $request->uploadedPath(), $request->clientName()));
    }

    /** `GET /media/{media}/download` — the stored original as an attachment. */
    #[OpenApiResponse(404, 'The file is missing on disk (`not-found`)')]
    public function download(Media $media): StreamedResponse
    {
        $this->authorize('view', $media);

        $disk = Storage::disk(MediaUrls::originalsDisk());
        $path = $media->originalPath();

        if (! $disk->exists($path)) {
            throw new NotFoundHttpException('The file is missing.');
        }

        return $disk->download($path, $media->downloadName(), [
            'Content-Type' => $media->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** `DELETE /media/{media}` — 409 `media-in-use` with `usages` when the file is used anywhere. */
    #[OpenApiResponse(409, 'The file is in use (`media-in-use`, with a `usages` list)')]
    public function destroy(Request $request, Media $media, DeleteMedia $deleteMedia): Response|JsonResponse
    {
        $this->authorize('delete', $media);

        try {
            $deleteMedia->handle($media);
        } catch (MediaInUse $e) {
            return (new ProblemDetails(
                ProblemType::MediaInUse,
                ProblemType::MediaInUse->status(),
                $e->getMessage(),
                extensions: ['usages' => array_map(MediaUsageResource::shape(...), $e->usages)],
            ))->toResponse($request);
        }

        return response()->noContent();
    }

    private function detail(Media $media): MediaDetailResource
    {
        $media->load(MediaResource::RELATIONS);

        return new MediaDetailResource($media, $this->usages->for($media->id));
    }
}
