<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Media\Exceptions\SystemFolderLocked;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Models\MediaFolder;
use Illuminate\Support\Facades\DB;

/**
 * Delete a custom folder. Its files become not filed (`folder_id` null); no file is
 * deleted. System folders cannot be deleted (409).
 */
final class DeleteMediaFolder
{
    /** @throws SystemFolderLocked */
    public function handle(MediaFolder $folder): void
    {
        DB::transaction(static function () use ($folder): void {
            $folder = MediaFolder::query()->lockForUpdate()->find($folder->id);

            if ($folder === null) {
                return;
            }

            if ($folder->isSystem()) {
                throw new SystemFolderLocked;
            }

            Media::query()->where('folder_id', $folder->id)->update(['folder_id' => null, 'updated_at' => now()]);
            $folder->delete();
        });
    }
}
