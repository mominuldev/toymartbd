<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Media\Exceptions\SystemFolderLocked;
use App\Domain\Catalog\Media\Models\MediaFolder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a custom media folder, or rename one. System folders cannot be renamed (409).
 * Names are unique case-insensitively (`name_key`); a clash is a 422 on `name`.
 */
final class SaveMediaFolder
{
    /** @throws SystemFolderLocked */
    public function handle(?MediaFolder $folder, string $name): MediaFolder
    {
        $name = trim($name);
        $key = MediaFolder::key($name);

        return DB::transaction(function () use ($folder, $name, $key): MediaFolder {
            if ($folder !== null) {
                $folder = MediaFolder::query()->lockForUpdate()->findOrFail($folder->id);

                if ($folder->isSystem()) {
                    throw new SystemFolderLocked;
                }
            }

            $clash = MediaFolder::query()->where('name_key', $key)
                ->when($folder !== null, static fn ($q) => $q->whereKeyNot($folder?->id))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages(['name' => (string) __('media.folder.name_taken')]);
            }

            $folder ??= new MediaFolder(['position' => MediaFolder::CUSTOM_POSITION]);
            $folder->fill(['name' => $name, 'name_key' => $key])->save();

            return $folder;
        });
    }
}
