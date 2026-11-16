<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\StoreSettings;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Content\Services\SiteBranding;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Store settings › Site & branding (Figma B · 38 Store settings · Premium). Null = not set: the
 * storefront then uses `fallback_names`, its built-in wordmark and its own icon.
 *
 * @property array{values: array<string, string|int|null>, media: array<string, Media|null>, fallback_names: array{name: string, name_bn: string}} $resource
 */
#[SchemaName('SiteBranding')]
final class SiteBrandingResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $v = $this->resource['values'];
        $m = $this->resource['media'];
        $text = static fn (string $f): ?string => is_string($v[$f] ?? null) ? $v[$f] : null;

        return [
            /** @var string|null */
            'name' => $text('name'),
            /** @var string|null */
            'name_bn' => $text('name_bn'),
            /** @var string|null */
            'tagline' => $text('tagline'),
            /** @var string|null */
            'tagline_bn' => $text('tagline_bn'),
            /** @var string|null */
            'description' => $text('description'),
            /** @var string|null */
            'description_bn' => $text('description_bn'),
            /**
             * Logo for light backgrounds.
             *
             * @var array{id: int, url: string|null, width: int|null, height: int|null, filename: string}|null
             */
            'logo' => self::image($m['logo_media_id'] ?? null),
            /**
             * Optional logo for dark backgrounds.
             *
             * @var array{id: int, url: string|null, width: int|null, height: int|null, filename: string}|null
             */
            'logo_dark' => self::image($m['logo_dark_media_id'] ?? null),
            /**
             * Square store icon the favicons (32, 180, 192, 512 px PNG) are made from.
             *
             * @var array{id: int, url: string|null, width: int|null, height: int|null, filename: string}|null
             */
            'icon' => self::image($m['icon_media_id'] ?? null),
            /**
             * The image a shared link shows (1.91 : 1).
             *
             * @var array{id: int, url: string|null, width: int|null, height: int|null, filename: string}|null
             */
            'social_image' => self::image($m['social_image_media_id'] ?? null),
            /**
             * What the storefront shows while a name is blank: the business name, else the brand.
             *
             * @var array{name: string, name_bn: string}
             */
            'fallback_names' => $this->resource['fallback_names'],
            /**
             * Most characters per text field.
             *
             * @var array{name: int, name_bn: int, tagline: int, tagline_bn: int, description: int, description_bn: int}
             */
            'max_lengths' => SiteBranding::TEXT_FIELDS,
        ];
    }

    /** @return array{id: int, url: string|null, width: int|null, height: int|null, filename: string}|null */
    private static function image(?Media $media): ?array
    {
        return $media === null ? null : [
            'id' => $media->id,
            'url' => $media->url(480),
            'width' => $media->width,
            'height' => $media->height,
            'filename' => $media->filename,
        ];
    }
}
