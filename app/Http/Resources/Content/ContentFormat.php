<?php

declare(strict_types=1);

namespace App\Http\Resources\Content;

use App\Domain\Catalog\Media\Models\Media;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Shared shapes for the content resources: UTC timestamps and a media-library image. */
final class ContentFormat
{
    /** `2026-11-11T09:30:00Z`, or null. */
    public static function utc(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
    }

    /**
     * A library image, or null when there is none or its renditions are not ready yet.
     *
     * @return array{media_id: int, url: string, srcset: string|null, width: int|null, height: int|null, alt: string|null, alt_bn: string|null}|null
     */
    public static function image(?Media $media, int $width = 1200): ?array
    {
        $url = $media?->url($width);

        if ($media === null || $url === null) {
            return null;
        }

        return [
            'media_id' => $media->id,
            'url' => $url,
            'srcset' => $media->srcset(),
            'width' => $media->width,
            'height' => $media->height,
            'alt' => $media->alt_text,
            'alt_bn' => $media->alt_text_bn,
        ];
    }
}
