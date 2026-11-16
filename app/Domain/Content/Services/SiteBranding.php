<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Models\Media;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;

/**
 * Store settings › Site & branding (Figma B · 38 Store settings · Premium): how the storefront
 * introduces itself — its name and tagline in both locales, the search/social description, the
 * logo (plus an optional one for dark backgrounds), the square store icon the favicons are made
 * from, and the image a shared link shows. Stored in `settings` (group `store`, keys `store.*`).
 *
 * The storefront name is its own value, not the business name: the shop's legal name on an
 * invoice ("Toy Mart BD Ltd") and the name in a browser tab ("Toy Mart BD") may differ. Unset, it
 * falls back to the business name, then the brand ({@see self::pickName()}).
 *
 * Brand colours are NOT here, on purpose: they come from the design tokens (packages/tokens) so
 * every page keeps its contrast; the screen shows them read-only.
 */
final class SiteBranding
{
    public const string PREFIX = 'store.';

    /** Text fields → max length. `name` / `tagline` / `description` are English; `_bn` Bangla. */
    public const array TEXT_FIELDS = [
        'name' => 60,
        'name_bn' => 60,
        'tagline' => 80,
        'tagline_bn' => 80,
        'description' => 160,
        'description_bn' => 160,
    ];

    /** Media fields, each a ready library image (`media.id`). */
    public const array MEDIA_FIELDS = ['logo_media_id', 'logo_dark_media_id', 'icon_media_id', 'social_image_media_id'];

    /** The store icon: square, at least this many px per side (the largest app icon generated). */
    public const int ICON_MIN = 512;

    /** Favicon and app-icon sizes generated from the store icon: tab, Apple touch, Android, PWA splash. */
    public const array ICON_SIZES = [32, 180, 192, 512];

    /** The share image: 1.91:1 like Facebook's 1200 × 630, at least this wide. */
    public const int SOCIAL_MIN_WIDTH = 600;

    public const float SOCIAL_RATIO = 1200 / 630;

    /** The share image a shared link carries: a JPEG every app previews (WebP is not universal). */
    public const array SOCIAL_SIZE = [1200, 630];

    /** Logos: a readable minimum width. */
    public const int LOGO_MIN_WIDTH = 120;

    public static function key(string $field): string
    {
        return self::PREFIX.$field;
    }

    /** @return list<string> every key this section owns */
    public static function keys(): array
    {
        return array_map(self::key(...), [...array_keys(self::TEXT_FIELDS), ...self::MEDIA_FIELDS]);
    }

    /**
     * Saved values only (null when unset): text trimmed, media ids as int.
     *
     * @return array<string, string|int|null>
     */
    public function saved(): array
    {
        $rows = Setting::query()
            ->where('group', SettingGroup::Store->value)
            ->whereIn('key', self::keys())
            ->get()
            ->keyBy('key');

        $values = [];
        foreach (array_keys(self::TEXT_FIELDS) as $field) {
            $raw = $rows->get(self::key($field))?->value;
            $values[$field] = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
        }
        foreach (self::MEDIA_FIELDS as $field) {
            $raw = $rows->get(self::key($field))?->value;
            $values[$field] = is_int($raw) || (is_string($raw) && ctype_digit($raw)) ? (int) $raw : null;
        }

        return $values;
    }

    /**
     * The saved media rows that are still ready images, by field (null when unset or gone).
     *
     * @param  array<string, string|int|null>|null  $saved
     * @return array<string, Media|null>
     */
    public function media(?array $saved = null): array
    {
        $saved ??= $this->saved();
        $ids = array_values(array_filter(array_map(static fn (string $f): mixed => $saved[$f] ?? null, self::MEDIA_FIELDS), is_int(...)));
        $rows = $ids === [] ? collect() : Media::query()
            ->whereKey($ids)
            ->where('kind', MediaKind::Image->value)
            ->where('processing_status', ImageProcessingStatus::Ready->value)
            ->get()
            ->keyBy('id');

        $out = [];
        foreach (self::MEDIA_FIELDS as $field) {
            $id = $saved[$field] ?? null;
            $media = is_int($id) ? $rows->get($id) : null;
            $out[$field] = $media instanceof Media ? $media : null;
        }

        return $out;
    }

    /** The first non-blank of the saved site name and the business name, else the brand default. */
    public static function pickName(?string $site, ?string $business, string $default): string
    {
        foreach ([$site, $business] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return $default;
    }

    /** True when a store icon of these dimensions is square (±1 %) and at least ICON_MIN per side. */
    public static function iconFits(?int $width, ?int $height): bool
    {
        return $width !== null && $height !== null && min($width, $height) >= self::ICON_MIN && abs($width - $height) <= max(1, (int) round($width * 0.01));
    }

    /** True when a share image is about 1.91:1 (1.8–2.0) and at least SOCIAL_MIN_WIDTH wide. */
    public static function socialFits(?int $width, ?int $height): bool
    {
        if ($width === null || $height === null || $height === 0 || $width < self::SOCIAL_MIN_WIDTH) {
            return false;
        }
        $ratio = $width / $height;

        return $ratio >= 1.8 && $ratio <= 2.0;
    }
}
