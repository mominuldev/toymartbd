<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Jobs\DeleteMediaFiles;
use App\Domain\Catalog\Media\Exceptions\MediaInUse;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Media\Services\MediaUsages;
use Illuminate\Support\Facades\DB;

/**
 * Delete an unused media row (A25 rule 2). The row is locked first, so an attach
 * racing this delete either lands before (and the delete is refused) or finds the row
 * gone; the `product_images.media_id` foreign key (RESTRICT) is the last line. The
 * files go after commit (rule 4). No soft delete.
 */
final readonly class DeleteMedia
{
    public function __construct(private MediaUsages $usages) {}

    /** @throws MediaInUse */
    public function handle(Media $media): void
    {
        DB::transaction(function () use ($media): void {
            $media = Media::query()->lockForUpdate()->find($media->id);

            if ($media === null) {
                return;
            }

            $usages = $this->usages->for($media->id);

            if ($usages !== []) {
                throw new MediaInUse($media->id, $usages);
            }

            $base = $media->base_path;
            $media->delete();

            DeleteMediaFiles::dispatch($base);
        });
    }
}
