<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Media\Models\Media;
use Illuminate\Support\Facades\DB;

/** Move many files to one folder, or un-file them (`null`). Returns how many moved. */
final class BulkMoveMedia
{
    /** @param list<int> $ids */
    public function handle(array $ids, ?int $folderId): int
    {
        return DB::transaction(static fn (): int => Media::query()->whereIn('id', $ids)->update([
            'folder_id' => $folderId,
            'updated_at' => now(),
        ]));
    }
}
