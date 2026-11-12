<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\ProductImageMediaMirror;
use Illuminate\Support\Facades\DB;

/**
 * Edit a media row's alt text, caption or folder. Only the keys present change. Alt
 * text is shared with every product image that uses the file (one source of truth),
 * so an alt change is mirrored and those products are revalidated.
 */
final readonly class UpdateMedia
{
    public function __construct(private ProductImageMediaMirror $mirror) {}

    /**
     * @param  array{alt_text?: string|null, alt_text_bn?: string|null, caption?: string|null, folder_id?: int|null}  $changes
     */
    public function handle(Media $media, array $changes): Media
    {
        return DB::transaction(function () use ($media, $changes): Media {
            $media = Media::query()->lockForUpdate()->findOrFail($media->id);

            foreach (['alt_text', 'alt_text_bn', 'caption'] as $key) {
                if (array_key_exists($key, $changes)) {
                    $media->{$key} = UploadMedia::clean($changes[$key]);
                }
            }

            if (! $media->isImage()) {
                $media->alt_text = null;
                $media->alt_text_bn = null;
            }

            if (array_key_exists('folder_id', $changes)) {
                $media->folder_id = $changes['folder_id'];
            }

            $altChanged = $media->isDirty(['alt_text', 'alt_text_bn']);

            if ($media->isDirty()) {
                $media->save();
            }

            if ($altChanged) {
                $this->mirror->sync($media);
            }

            return $media;
        });
    }
}
