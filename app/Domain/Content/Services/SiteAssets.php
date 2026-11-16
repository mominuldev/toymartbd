<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\Storage;

/**
 * The fixed-size files the storefront's <head> needs from the Site & branding images, which the
 * media pipeline (WebP/AVIF at 160–1200 wide) does not make: favicon / Apple touch / Android icons
 * as square PNGs (32, 180, 192, 512) and the share image as a 1200 × 630 JPEG.
 *
 * Made on first request and kept beside the media's renditions on the public media disk
 * (`{base}/site-icon-{size}.png`, `{base}/site-share.jpg`). A media base never changes in place
 * (Replace moves to a new base) and deleting the media removes its whole directory, so a cached
 * file can never go stale or outlive its image. Never called inside a transaction (rule 4).
 */
final readonly class SiteAssets
{
    public const string SHARE = 'share.jpg';

    public function __construct(private ImagePipeline $pipeline, private SiteBranding $branding) {}

    /**
     * Every public asset name: `icon-32.png` … `icon-512.png`, `share.jpg`.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [...array_map(static fn (int $s): string => "icon-{$s}.png", SiteBranding::ICON_SIZES), self::SHARE];
    }

    /**
     * The public URL of a generated asset for the CURRENT branding media, or null when that media
     * is not `$mediaId` (an old link), is unset, or the master is gone.
     */
    public function url(int $mediaId, string $name): ?string
    {
        if (! in_array($name, self::names(), true)) {
            return null;
        }
        $field = $name === self::SHARE ? 'social_image_media_id' : 'icon_media_id';
        $media = $this->branding->media()[$field];
        if (! $media instanceof Media || $media->id !== $mediaId) {
            return null;
        }

        $path = "{$media->base_path}/site-{$name}";
        $disk = Storage::disk(MediaUrls::disk());
        if (! $disk->exists($path)) {
            $master = Storage::disk(MediaUrls::originalsDisk())->get($media->originalPath());
            if ($master === null) {
                return null;
            }
            [$width, $height, $format] = $name === self::SHARE
                ? [...SiteBranding::SOCIAL_SIZE, 'jpeg']
                : [(int) substr($name, 5, -4), (int) substr($name, 5, -4), 'png'];
            $disk->put($path, $this->pipeline->fixed($master, $width, $height, $format), ['visibility' => 'public']);
        }

        return MediaUrls::url($path);
    }
}
