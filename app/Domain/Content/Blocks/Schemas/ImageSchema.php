<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;

/**
 * A single image (2026-11-16): one picture from the media library with alt text, an optional
 * caption and an optional link (store path or https), at the text width (`content`) or the full
 * 1280 content width (`wide`). A new block has no image yet, so `media_id` may be null in the
 * draft; the storefront never receives an Image block without one (LayoutResolver drops it).
 */
final class ImageSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            'media_id' => ['nullable', 'integer', self::imageMedia()],
            ...self::text('alt', 200, false),
            ...self::text('caption', 300, false),
            'href' => self::href(),
            'width' => ['sometimes', Rule::in(['content', 'wide'])],
        ];
    }

    public function defaults(): array
    {
        return ['media_id' => null, 'alt' => null, 'alt_bn' => null, 'caption' => null, 'caption_bn' => null, 'href' => null, 'width' => 'content'];
    }

    public function normalize(array $content): array
    {
        $out = ['media_id' => isset($content['media_id']) && is_numeric($content['media_id']) ? (int) $content['media_id'] : null];
        self::copyText($content, $out, 'alt', 'caption');
        $out['href'] = self::str($content['href'] ?? null);
        $out['width'] = ($content['width'] ?? null) === 'wide' ? 'wide' : 'content';

        return $out;
    }
}
