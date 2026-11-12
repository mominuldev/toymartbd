<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Media\Actions\DeleteMediaFolder;
use App\Domain\Catalog\Media\Actions\SaveMediaFolder;
use App\Domain\Catalog\Media\Models\MediaFolder;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Media\MediaFolderRequest;
use App\Http\Resources\Admin\MediaFolderResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Flat media-library folders (docs/api/media-library.md, decision 1). System folders
 * cannot be renamed or deleted (409 `conflict`).
 */
final class MediaFolderController extends ApiController
{
    /** `GET /media/folders` — system folders first, then custom folders by name. */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MediaFolder::class);

        return MediaFolderResource::collection(MediaFolder::query()->ordered()->get());
    }

    /** `POST /media/folders` — 201; a duplicate name (case-insensitive) is 422 on `name`. */
    public function store(MediaFolderRequest $request, SaveMediaFolder $save): JsonResponse
    {
        return (new MediaFolderResource($save->handle(null, $request->folderName())))->response()->setStatusCode(201);
    }

    /** `PATCH /media/folders/{folder}` — rename a custom folder. */
    #[OpenApiResponse(409, 'A system folder (`conflict`)')]
    public function update(MediaFolderRequest $request, MediaFolder $folder, SaveMediaFolder $save): MediaFolderResource
    {
        return new MediaFolderResource($save->handle($folder, $request->folderName()));
    }

    /** `DELETE /media/folders/{folder}` — its files become not filed; nothing is deleted. */
    #[OpenApiResponse(409, 'A system folder (`conflict`)')]
    public function destroy(MediaFolder $folder, DeleteMediaFolder $delete): Response
    {
        $this->authorize('delete', $folder);

        $delete->handle($folder);

        return response()->noContent();
    }
}
