<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\MediaUrls;
use App\Models\Setting;

/**
 * Store settings › Site & branding (Figma B · 38 Premium `390:69035`): the storefront's site
 * name, tagline and search/social description (EN/BN), and its logo, dark-background logo,
 * store icon and social share image. Stored in `settings` (group `store`, keys
 * `store.branding.*`) — except the logo, which IS `invoice.company.logo_media_id`: one logo for
 * the storefront header, invoices and emails, never two copies of it.
 *
 * The store icon's PNG favicons (32/180/192/512) are rendered by the queued
 * RenderStoreFavicons job into `store.branding.icon_pngs`; they count only while they were
 * rendered from the icon currently chosen.
 */
final class StoreBranding
{
    public const string PREFIX = 'store.branding.';

    /** Text field → max length (characters). */
    public const array TEXT_FIELDS = [
        'site_name_en' => 60,
        'site_name_bn' => 60,
        'tagline_en' => 80,
        'tagline_bn' => 80,
        'description_en' => 160,
        'description_bn' => 160,
    ];

    /** The limits the screen shows next to each counter. */
    public const array LIMITS = ['site_name' => 60, 'tagline' => 80, 'description' => 160];

    /** Media field → its `settings` key. The logo is the invoice logo's row. */
    public const array MEDIA_KEYS = [
        'logo_media_id' => CompanyDetails::PREFIX.'logo_media_id',
        'logo_dark_media_id' => self::PREFIX.'logo_dark_media_id',
        'icon_media_id' => self::PREFIX.'icon_media_id',
        'share_image_media_id' => self::PREFIX.'share_image_media_id',
    ];

    /** Where the rendered favicons are recorded: `{media_id, paths: {"32": path, …}}`. */
    public const string ICON_PNGS_KEY = self::PREFIX.'icon_pngs';

    /** Favicon sizes rendered from the store icon (px, square). */
    public const array ICON_SIZES = [32, 180, 192, 512];

    public const int ICON_MIN = 512;

    /** |width − height| may be at most this share of the longer side. */
    public const float ICON_SQUARE_TOLERANCE = 0.01;

    public const int SHARE_MIN_WIDTH = 1200;

    public const float SHARE_RATIO = 1.91;

    public const float SHARE_RATIO_TOLERANCE = 0.03;

    /**
     * Saved values only (null when unset), keyed by the API field names.
     *
     * @return array<string, string|int|null>
     */
    public function saved(): array
    {
        $keys = [...array_map(static fn (string $f): string => self::PREFIX.$f, array_keys(self::TEXT_FIELDS)), ...array_values(self::MEDIA_KEYS)];
        $rows = Setting::query()->whereIn('key', $keys)->get()->keyBy('key');

        $values = [];
        foreach (array_keys(self::TEXT_FIELDS) as $field) {
            $raw = $rows->get(self::PREFIX.$field)?->value;
            $values[$field] = is_string($raw) && trim($raw) !== '' ? $raw : null;
        }
        foreach (self::MEDIA_KEYS as $field => $key) {
            $values[$field] = self::id($rows->get($key)?->value);
        }

        return $values;
    }

    /**
     * The usable Media row for a saved id: an image whose renditions are ready, else null
     * (deleted since, or still processing).
     */
    public static function readyImage(?int $mediaId): ?Media
    {
        if ($mediaId === null) {
            return null;
        }
        $media = Media::query()->find($mediaId);

        return $media instanceof Media && $media->isRaster() && $media->processing_status === ImageProcessingStatus::Ready ? $media : null;
    }

    /**
     * Why a media row cannot fill a branding slot, as a `store_settings.*` translation key —
     * or null when it can. Every slot needs an existing, ready image; the icon must also be
     * square and at least 512 px, the share image at least 1200 px wide at 1.91:1.
     */
    public static function mediaProblem(string $field, ?Media $media): ?string
    {
        if (! $media instanceof Media) {
            return 'store_settings.branding_media_missing';
        }
        if (! $media->isImage()) {
            return 'store_settings.branding_media_not_image';
        }
        // Branding feeds favicons, invoice PDFs, emails and social previews — none take SVG.
        if ($media->isVector()) {
            return 'store_settings.branding_media_svg';
        }
        if ($media->processing_status !== ImageProcessingStatus::Ready) {
            return 'store_settings.branding_media_not_ready';
        }
        $w = (int) $media->width;
        $h = (int) $media->height;

        if ($field === 'icon_media_id') {
            if (abs($w - $h) > self::ICON_SQUARE_TOLERANCE * max($w, $h)) {
                return 'store_settings.branding_icon_not_square';
            }
            if ($w < self::ICON_MIN || $h < self::ICON_MIN) {
                return 'store_settings.branding_icon_too_small';
            }
        }

        if ($field === 'share_image_media_id') {
            if ($w < self::SHARE_MIN_WIDTH) {
                return 'store_settings.branding_share_too_small';
            }
            if ($h < 1 || abs($w / $h - self::SHARE_RATIO) > self::SHARE_RATIO_TOLERANCE * self::SHARE_RATIO) {
                return 'store_settings.branding_share_wrong_ratio';
            }
        }

        return null;
    }

    /**
     * Public URL and size of the rendition nearest `$width` (the smallest at least that wide,
     * else the largest), or null when the media is not a ready image.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    public static function rendition(?Media $media, int $width): ?array
    {
        if (! $media instanceof Media) {
            return null;
        }
        $chosen = MediaUrls::pick($media->renditionWidths(), $width);
        $url = $media->url($width);
        if ($chosen === null || $url === null) {
            return null;
        }
        $w = (int) $media->width;
        $h = (int) $media->height;

        return ['url' => self::absolute($url), 'width' => $chosen, 'height' => $w > 0 && $h > 0 ? (int) round($chosen * $h / $w) : $chosen];
    }

    /**
     * The rendered favicons' public URLs — only when they were rendered from the icon chosen
     * now; null before the job has run.
     *
     * @return array{png32: string, png180: string, png192: string, png512: string}|null
     */
    public function iconUrls(): ?array
    {
        $icon = self::id(Setting::get(self::MEDIA_KEYS['icon_media_id']));
        $pngs = Setting::get(self::ICON_PNGS_KEY);
        if ($icon === null || ! is_array($pngs) || self::id($pngs['media_id'] ?? null) !== $icon || ! is_array($pngs['paths'] ?? null)) {
            return null;
        }
        $urls = [];
        foreach (self::ICON_SIZES as $size) {
            $path = $pngs['paths'][(string) $size] ?? null;
            if (! is_string($path) || $path === '') {
                return null;
            }
            $urls['png'.$size] = self::absolute(MediaUrls::url($path));
        }

        /** @var array{png32: string, png180: string, png192: string, png512: string} $urls */
        return $urls;
    }

    /** A disk URL made absolute (a `public` disk without APP_URL answers `/storage/…`). */
    private static function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : url($url);
    }

    public static function id(mixed $raw): ?int
    {
        return match (true) {
            is_int($raw) => $raw,
            is_string($raw) && ctype_digit($raw) => (int) $raw,
            default => null,
        };
    }
}
