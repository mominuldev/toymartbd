<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Public URLs for media-pipeline renditions (config/media.php). A rendition lives at
 * `{base}/{width}.{format}` on the public media disk; MEDIA_CDN_URL, when set,
 * replaces the disk's own URL as the host.
 */
final class MediaUrls
{
    /** @return list<int> */
    public static function widths(): array
    {
        /** @var list<int> $widths */
        $widths = config('media.widths', [160, 320, 480, 800, 1200]);

        return $widths;
    }

    /** @return list<string> */
    public static function formats(): array
    {
        /** @var list<string> $formats */
        $formats = config('media.formats', ['webp', 'avif']);

        return $formats;
    }

    public static function renditionPath(string $base, int $width, string $format): string
    {
        return "{$base}/{$width}.{$format}";
    }

    public static function url(string $relativePath): string
    {
        $cdn = config('media.cdn_url');

        if (is_string($cdn) && $cdn !== '') {
            return rtrim($cdn, '/').'/'.ltrim($relativePath, '/');
        }

        return Storage::disk(self::disk())->url($relativePath);
    }

    public static function disk(): string
    {
        $disk = config('media.disk', 'public');

        return is_string($disk) ? $disk : 'public';
    }

    public static function originalsDisk(): string
    {
        $disk = config('media.originals_disk', 'local');

        return is_string($disk) ? $disk : 'local';
    }

    /**
     * The generated width to serve for a requested one: the smallest that is at
     * least `$requested`, else the largest. Null when nothing was generated.
     *
     * @param  list<int>  $available
     */
    public static function pick(array $available, ?int $requested): ?int
    {
        if ($available === []) {
            return null;
        }

        sort($available);

        if ($requested !== null) {
            foreach ($available as $width) {
                if ($width >= $requested) {
                    return $width;
                }
            }
        }

        return $available[count($available) - 1];
    }
}
