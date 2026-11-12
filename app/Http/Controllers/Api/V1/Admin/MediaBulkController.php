<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\Media\Actions\BuildMediaZip;
use App\Domain\Catalog\Media\Actions\BulkDeleteMedia;
use App\Domain\Catalog\Media\Actions\BulkMoveMedia;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\Media\BulkMediaRequest;
use App\Http\Requests\Admin\Media\BulkMoveMediaRequest;
use App\Support\DhakaDate;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk actions of the library's list view (B9 `BulkActionBar`). Move needs
 * media.manage; delete and download need media.delete.
 */
final class MediaBulkController extends ApiController
{
    /**
     * `POST /media/bulk/move` — move files to a folder, or un-file them (`folder_id: null`).
     *
     * @return array{data: array{moved: int}}
     */
    public function move(BulkMoveMediaRequest $request, BulkMoveMedia $move): array
    {
        return ['data' => ['moved' => $move->handle($request->ids(), $request->folderId())]];
    }

    /**
     * `POST /media/bulk/delete` — deletes what it can and skips files in use; per-id
     * `results` in request order.
     *
     * @return array{data: array{deleted: int, skipped: int, results: list<array{id: int, status: 'deleted'|'in_use'|'not_found', used_in_count: int}>}}
     */
    public function delete(BulkMediaRequest $request, BulkDeleteMedia $delete): array
    {
        $results = $delete->handle($request->ids());
        $deleted = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'deleted'));

        return ['data' => [
            'deleted' => $deleted,
            'skipped' => count($results) - $deleted,
            'results' => $results,
        ]];
    }

    /** `POST /media/bulk/download` — a zip of the originals; capped (422 on `ids` over it). */
    #[OpenApiResponse(200, 'The zip (`application/zip`, attachment)')]
    #[OpenApiResponse(422, 'Over the file-count or size cap, or an unknown id (`validation-error` on `ids`)')]
    public function download(BulkMediaRequest $request, BuildMediaZip $zip): BinaryFileResponse
    {
        $path = $zip->handle($request->ids());
        $name = 'toymartbd-media-'.now(DhakaDate::zone())->format('Ymd-Hi').'.zip';

        return response()->download($path, $name, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }
}
