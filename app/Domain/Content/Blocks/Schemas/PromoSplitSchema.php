<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;

/** Promo split: an image beside copy and one call to action; `image_side` picks the image's column. */
final class PromoSplitSchema extends BlockSchema
{
    public function rules(): array
    {
        return [
            ...self::text('overline', 60, false),
            ...self::text('headline', 120),
            ...self::text('body', 400, false),
            ...self::text('cta_label', 40, false),
            'cta_href' => [...self::href(), 'required_with:cta_label'],
            'media_id' => ['nullable', 'integer', self::imageMedia()],
            ...self::text('alt', 200, false),
            'image_side' => ['sometimes', Rule::in(['left', 'right'])],
        ];
    }

    public function defaults(): array
    {
        return [
            'overline' => null, 'overline_bn' => null,
            'headline' => 'Learning toys for curious minds', 'headline_bn' => 'কৌতূহলী মনের জন্য শেখার খেলনা',
            'body' => null, 'body_bn' => null, 'cta_label' => 'Shop now', 'cta_label_bn' => 'এখনই কিনুন', 'cta_href' => '/toys',
            'media_id' => null, 'alt' => null, 'alt_bn' => null, 'image_side' => 'left',
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'overline', 'headline', 'body', 'cta_label');
        $out['cta_href'] = self::str($content['cta_href'] ?? null);
        $out['media_id'] = isset($content['media_id']) && is_numeric($content['media_id']) ? (int) $content['media_id'] : null;
        self::copyText($content, $out, 'alt');
        $out['image_side'] = ($content['image_side'] ?? null) === 'right' ? 'right' : 'left';

        return $out;
    }
}
