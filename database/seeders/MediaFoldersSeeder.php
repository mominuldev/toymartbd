<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Media\Models\MediaFolder;
use Illuminate\Database\Seeder;

/**
 * The media library's fixed system folders (docs/api/media-library.md, decision 1):
 * Products, Category banners, Blog, Pages. The migration inserts them too; this
 * re-asserts them idempotently on every deploy without touching custom folders or a
 * system folder's id.
 */
class MediaFoldersSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MediaFolder::SYSTEM as $key => [$name, $position]) {
            MediaFolder::query()->updateOrCreate(
                ['system_key' => $key],
                ['name' => $name, 'name_key' => MediaFolder::key($name), 'position' => $position],
            );
        }
    }
}
