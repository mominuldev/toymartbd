<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Blog;

use App\Domain\Catalog\Media\Models\Media;

/** The image block shared by blog resources (cover, avatar). */
final class BlogImage
{
    /**
     * @return array{media_id: int, url: string, srcset_webp: string|null, srcset_avif: string|null, alt: string|null, alt_bn: string|null, width: int|null, height: int|null}|null
     */
    public static function of(?Media $media, int $width = 800): ?array
    {
        $url = $media?->url($width);
        if ($media === null || $url === null) {
            return null;
        }

        return [
            'media_id' => $media->id,
            'url' => $url,
            'srcset_webp' => $media->srcset('webp'),
            'srcset_avif' => $media->srcset('avif'),
            'alt' => $media->alt_text,
            'alt_bn' => $media->alt_text_bn,
            'width' => $media->width,
            'height' => $media->height,
        ];
    }
}
