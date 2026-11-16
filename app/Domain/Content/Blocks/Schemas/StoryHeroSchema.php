<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;

/**
 * Story hero (2026-11-16, About us 170:10502 / 174:8244): a Bloom-gradient band with an overline
 * pill, the page's headline, a lead and a second paragraph, beside a sticker-style collage of up
 * to three library photos and an optional round badge ("Ages / 0–12"). On a page built from
 * sections, a story hero placed first replaces the page header and its title is the page's H1.
 * The collage is decorative: with no photos the copy takes the full width.
 */
final class StoryHeroSchema extends BlockSchema
{
    public const array TONES = ['sunrise', 'sky', 'mint', 'candy'];

    public function rules(): array
    {
        return [
            ...self::text('overline', 40, false),
            ...self::text('title', 120),
            ...self::text('lead', 600),
            ...self::text('body', 600, false),
            'media_ids' => ['sometimes', 'array', 'max:3'],
            'media_ids.*' => ['integer', 'distinct', self::imageMedia()],
            ...self::text('badge', 20, false),
            ...self::text('badge_sub', 20, false),
            'tone' => ['sometimes', Rule::in(self::TONES)],
        ];
    }

    public function defaults(): array
    {
        return [
            'overline' => 'Our story', 'overline_bn' => 'আমাদের গল্প',
            'title' => 'About us', 'title_bn' => 'আমাদের সম্পর্কে',
            'lead' => 'Tell parents why the shop exists, in two or three sentences.',
            'lead_bn' => 'দোকানটা কেন, তা দুই-তিন বাক্যে অভিভাবকদের বলুন।',
            'body' => null, 'body_bn' => null,
            'media_ids' => [],
            'badge' => null, 'badge_bn' => null, 'badge_sub' => null, 'badge_sub_bn' => null,
            'tone' => 'sunrise',
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyText($content, $out, 'overline', 'title', 'lead', 'body', 'badge', 'badge_sub');
        $out['media_ids'] = array_slice(self::ids($content['media_ids'] ?? null), 0, 3);
        $tone = $content['tone'] ?? null;
        $out['tone'] = is_string($tone) && in_array($tone, self::TONES, true) ? $tone : 'sunrise';

        return $out;
    }
}
