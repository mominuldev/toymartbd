<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use App\Domain\Content\Blocks\ValueObjects\VideoEmbed;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Video reel (Smart Junior 55:12790 / 55:12912; replaces the testimonials, §10): the section header
 * (eyebrow chip, heading, line, "see all" link and prev/next) over a pastel `band`, then one to
 * twelve 9:16 reels. Each reel plays in the storefront's popup player: `video_url` must be a
 * YouTube (Shorts / watch / youtu.be), Facebook or Instagram video link, or an https link to an
 * .mp4 / .webm file — anything else has no player and is refused (VideoEmbed). A library poster, a two-line title, an optional duration and view-count
 * caption, and an optional product whose "shop this" chip links to its page. The duration and
 * views are typed, never fetched or invented.
 */
final class VideoReelSchema extends BlockSchema
{
    public const int MAX_ITEMS = 12;

    /** https only, never `javascript:` or a protocol-relative URL. */
    public const string VIDEO_URL_PATTERN = '#^https://[^\s/]+[^\s]*$#';

    public function rules(): array
    {
        return [
            ...self::headerRules(),
            'band' => self::toneRule(),
            ...self::text('see_all_label', 60, false),
            'see_all_href' => [...self::href(), 'required_with:see_all_label'],
            'items' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'items.*' => ['required', 'array'],
            ...self::prefixed('items.*.', [
                ...self::text('title', 120),
                ...self::text('views', 40, false),
                ...self::text('alt', 200, false),
                'video_url' => ['required', 'string', 'max:2048', 'regex:'.self::VIDEO_URL_PATTERN, static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && VideoEmbed::fromUrl($value) === null) {
                        $fail('Use a YouTube, Facebook or Instagram video link, or an https:// link to an .mp4 or .webm file.');
                    }
                }],
                'media_id' => ['required', 'integer', self::imageMedia()],
                'duration' => ['nullable', 'string', 'max:8', 'regex:/^\d{1,2}:\d{2}(?::\d{2})?$/'],
                'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            ]),
        ];
    }

    /** A fresh reel has no videos yet; the storefront leaves a reel without any out. */
    public function defaults(): array
    {
        return [
            'eyebrow' => 'Watch & play', 'eyebrow_bn' => 'দেখুন ও খেলুন', 'eyebrow_tone' => 'pink',
            'title' => 'Toys in action', 'title_bn' => 'খেলার মুহূর্ত', 'sub' => null, 'sub_bn' => null,
            'band' => 'purple', 'see_all_label' => null, 'see_all_label_bn' => null, 'see_all_href' => null,
            'items' => [],
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyHeader($content, $out, 'pink');
        $out['band'] = self::tone($content['band'] ?? null, null);
        self::copyText($content, $out, 'see_all_label');
        $out['see_all_href'] = self::str($content['see_all_href'] ?? null);
        $out['items'] = [];
        foreach (self::items($content, 'items', self::MAX_ITEMS) as $item) {
            $row = [];
            self::copyText($item, $row, 'title');
            $row['video_url'] = self::str($item['video_url'] ?? null);
            $row['media_id'] = self::mediaId($item['media_id'] ?? null);
            self::copyText($item, $row, 'alt');
            $row['duration'] = self::str($item['duration'] ?? null);
            self::copyText($item, $row, 'views');
            $row['product_id'] = self::mediaId($item['product_id'] ?? null);
            $out['items'][] = $row;
        }

        return $out;
    }
}
