<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * "Why Smart Junior" (34:5786 / 34:6082 / 34:5972): a dark band with confetti — an eyebrow chip,
 * the heading, a paragraph, up to four figures and a library photo. The figures (`stats[].value`,
 * e.g. "50,000+") are typed by the owner and shown exactly as typed: the block never computes or
 * defaults a business figure. A figure is plain text with Western digits (rule 36), shared by both
 * languages; its caption (`label`) is bilingual.
 */
final class WhyBandSchema extends BlockSchema
{
    public const int MAX_STATS = 4;

    public function rules(): array
    {
        return [
            ...self::text('eyebrow', 60, false),
            ...self::text('title', 160),
            ...self::text('body', 600, false),
            'stats' => ['sometimes', 'array', 'max:'.self::MAX_STATS],
            'stats.*' => ['required', 'array'],
            'stats.*.value' => ['required', 'string', 'max:16'],
            ...self::prefixed('stats.*.', self::text('label', 40)),
            'media_id' => ['nullable', 'integer', self::imageMedia()],
            ...self::text('alt', 200, false),
        ];
    }

    public function defaults(): array
    {
        return [
            'eyebrow' => 'Why parents choose us', 'eyebrow_bn' => 'অভিভাবকরা কেন আমাদের বেছে নেন',
            'title' => 'Every toy is chosen for how it helps a child grow', 'title_bn' => 'প্রতিটি খেলনা বাছাই করা হয় শিশুর বেড়ে ওঠায় তার ভূমিকা দেখে',
            'body' => null, 'body_bn' => null,
            'stats' => [],
            'media_id' => null, 'alt' => null, 'alt_bn' => null,
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'eyebrow', 'title', 'body');
        $out['stats'] = [];
        foreach (self::items($content, 'stats', self::MAX_STATS) as $stat) {
            $value = self::str($stat['value'] ?? null);
            if ($value === null) {
                continue;
            }
            $row = ['value' => $value];
            self::copyText($stat, $row, 'label');
            $out['stats'][] = $row;
        }
        $out['media_id'] = self::mediaId($content['media_id'] ?? null);
        self::copyText($content, $out, 'alt');

        return $out;
    }
}
