<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Actions;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

/**
 * Zip the originals of the chosen files (bulk download). Capped by
 * `media.zip_max_files` and `media.zip_max_bytes` (recorded sizes, checked before any
 * disk read) — over either is a 422 on `ids`. No transaction: read-only.
 */
final class BuildMediaZip
{
    /**
     * @param  list<int>  $ids
     * @return string a temporary file the caller streams and deletes
     */
    public function handle(array $ids): string
    {
        $maxFiles = config('media.zip_max_files');
        $maxFiles = is_int($maxFiles) ? $maxFiles : 100;
        $maxBytes = config('media.zip_max_bytes');
        $maxBytes = is_int($maxBytes) ? $maxBytes : 500 * 1048576;

        if (count($ids) > $maxFiles) {
            throw ValidationException::withMessages(['ids' => (string) __('media.zip.too_many', ['max' => $maxFiles, 'count' => count($ids)])]);
        }

        $media = Media::query()->whereIn('id', $ids)->get()->sortBy(static fn (Media $m): int => (int) array_search($m->id, $ids, true))->values();
        $bytes = (int) $media->sum('master_bytes');

        if ($bytes > $maxBytes) {
            throw ValidationException::withMessages(['ids' => (string) __('media.zip.too_large', [
                'size' => sprintf('%.1f', $bytes / 1048576),
                'limit' => intdiv($maxBytes, 1048576),
            ])]);
        }

        $path = tempnam(sys_get_temp_dir(), 'media-zip-');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the zip.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the zip for writing.');
        }

        $disk = Storage::disk(MediaUrls::originalsDisk());
        $names = [];

        foreach ($media as $item) {
            $contents = $disk->get($item->originalPath());

            if ($contents === null) {
                continue; // file missing on disk; the rest still download
            }

            $zip->addFromString(self::uniqueName($item->downloadName(), $names), $contents);
        }

        if ($zip->count() === 0) {
            $zip->addFromString('README.txt', "None of the selected files could be read.\n");
        }

        $zip->close();

        return $path;
    }

    /** @param array<string, true> $names */
    private static function uniqueName(string $name, array &$names): string
    {
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $candidate = $name;

        for ($i = 2; isset($names[strtolower($candidate)]); $i++) {
            $candidate = "{$stem} ({$i}).{$ext}";
        }

        $names[strtolower($candidate)] = true;

        return $candidate;
    }
}
