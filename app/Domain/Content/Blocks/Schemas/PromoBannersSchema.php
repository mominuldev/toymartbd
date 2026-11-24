<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Promo banners (Smart Junior 34:5763; mobile "Gift banner" 34:5966): one or two banners side by
 * side, each an eyebrow, a heading, a line, a button and a library photo on a pastel `tone`.
 * On mobile they stack. All copy, links and images are the editor's.
 */
final class PromoBannersSchema extends BlockSchema
{
    public const int MAX_ITEMS = 2;

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['required', 'array'],
            ...self::prefixed('items.*.', [
                ...self::text('overline', 60, false),
                ...self::text('title', 120),
                ...self::text('body', 240, false),
                ...self::text('cta_label', 40, false),
                ...self::text('alt', 200, false),
                'cta_href' => [...self::href(), 'required_with:items.*.cta_label'],
                'media_id' => ['nullable', 'integer', self::imageMedia()],
                'tone' => self::toneRule(),
            ]),
        ];
    }

    public function defaults(): array
    {
        return ['items' => [
            [
                'overline' => 'Gift shop', 'overline_bn' => 'গিফট শপ',
                'title' => 'Birthday boxes, wrapped and ready', 'title_bn' => 'জন্মদিনের বক্স, র‍্যাপ করা ও প্রস্তুত',
                'body' => null, 'body_bn' => null,
                'cta_label' => 'Shop gift combos', 'cta_label_bn' => 'গিফট কম্বো কিনুন', 'cta_href' => '/toys',
                'media_id' => null, 'alt' => null, 'alt_bn' => null, 'tone' => 'purple',
            ],
        ]];
    }

    public function normalize(array $content): array
    {
        $items = [];
        foreach (self::items($content, 'items', self::MAX_ITEMS) as $i => $item) {
            $row = [];
            self::copyText($item, $row, 'overline', 'title', 'body', 'cta_label');
            $row['cta_href'] = self::str($item['cta_href'] ?? null);
            $row['media_id'] = self::mediaId($item['media_id'] ?? null);
            self::copyText($item, $row, 'alt');
            $row['tone'] = self::tone($item['tone'] ?? null, $i === 0 ? 'purple' : 'green');
            $items[] = $row;
        }

        return ['items' => $items];
    }
}
