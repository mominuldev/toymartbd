<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * "Shop by age" (Smart Junior 34:5735 / 34:6058 / 34:5944): the section header (eyebrow chip,
 * heading, line) and either the editor's photo `cards` — each a title, a short line, a link, a
 * library photo and a pastel tone — or, with no cards, the seven live age bands with their counts.
 * Every word on the section comes from the block; nothing is drawn from storefront constants.
 */
final class AgeTilesSchema extends BlockSchema
{
    public const int MAX_CARDS = 8;

    public function rules(): array
    {
        return [
            ...self::headerRules(),
            'show_see_all' => ['sometimes', 'boolean'],
            'cards' => ['sometimes', 'array', 'max:'.self::MAX_CARDS],
            'cards.*' => ['required', 'array'],
            ...self::prefixed('cards.*.', [
                ...self::text('title', 60),
                ...self::text('line', 120, false),
                ...self::text('alt', 200, false),
                'href' => self::href(true),
                'media_id' => ['nullable', 'integer', self::imageMedia()],
                'tone' => self::toneRule(),
            ]),
        ];
    }

    public function defaults(): array
    {
        return [
            'eyebrow' => 'Age guide', 'eyebrow_bn' => 'বয়স গাইড', 'eyebrow_tone' => 'blue',
            'title' => 'Shop by age', 'title_bn' => 'বয়স অনুযায়ী কিনুন',
            'sub' => 'Every age plays differently. Start with your child’s age.', 'sub_bn' => 'প্রতিটি বয়সের খেলা আলাদা। আপনার শিশুর বয়স দিয়ে শুরু করুন।',
            'show_see_all' => true,
            'cards' => [],
        ];
    }

    public function normalize(array $content): array
    {
        $out = [];
        self::copyHeader($content, $out, 'blue');
        $out['show_see_all'] = self::bool($content['show_see_all'] ?? null, true);
        $out['cards'] = [];
        foreach (self::items($content, 'cards', self::MAX_CARDS) as $i => $card) {
            $row = [];
            self::copyText($card, $row, 'title', 'line');
            $row['href'] = self::str($card['href'] ?? null);
            $row['media_id'] = self::mediaId($card['media_id'] ?? null);
            self::copyText($card, $row, 'alt');
            $row['tone'] = self::tone($card['tone'] ?? null, self::TONES[[3, 0, 2, 1, 4, 5][$i % 6]]);
            $out['cards'][] = $row;
        }

        return $out;
    }
}
