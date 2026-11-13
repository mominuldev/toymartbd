<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Hero / banner carousel (Figma HeroCard 133:188 / 126:101). One to five slides; the first is the
 * page's LCP element. A slide without `media_id` uses the built-in hero art. `show_age_pills`
 * adds the "Tell us the age" chooser under the first slide's copy.
 */
final class HeroSchema extends BlockSchema
{
    public const int MAX_SLIDES = 5;

    public function rules(): array
    {
        return [
            'show_age_pills' => ['sometimes', 'boolean'],
            'slides' => ['required', 'array', 'min:1', 'max:'.self::MAX_SLIDES],
            'slides.*' => ['required', 'array'],
            ...$this->slide('slides.*.'),
        ];
    }

    /** @return array<string, mixed> */
    private function slide(string $p): array
    {
        $rules = [];
        foreach ([...self::text('overline', 60, false), ...self::text('headline', 120), ...self::text('sub', 240, false), ...self::text('cta_label', 40, false), ...self::text('alt', 200, false)] as $key => $rule) {
            $rules[$p.$key] = $rule;
        }
        $rules[$p.'cta_href'] = [...self::href(), 'required_with:'.$p.'cta_label'];
        $rules[$p.'media_id'] = ['nullable', 'integer', self::imageMedia()];

        return $rules;
    }

    public function defaults(): array
    {
        return [
            'show_age_pills' => true,
            'slides' => [[
                'overline' => 'Shop by age', 'overline_bn' => 'বয়স অনুযায়ী খেলনা',
                'headline' => 'Tell us the age. See the right toys.', 'headline_bn' => 'বয়স বলুন, সঠিক খেলনা দেখুন',
                'sub' => 'Toys for 0–12 years. Age, price and stock stated on every one.', 'sub_bn' => '0–12 বছরের খেলনা। প্রতিটিতে বয়স, দাম আর স্টক পরিষ্কার লেখা।',
                'cta_label' => 'Browse all toys', 'cta_label_bn' => 'সব খেলনা দেখুন', 'cta_href' => '/toys',
                'media_id' => null, 'alt' => null, 'alt_bn' => null,
            ]],
        ];
    }

    public function normalize(array $content): array
    {
        $slides = [];
        foreach (is_array($content['slides'] ?? null) ? $content['slides'] : [] as $slide) {
            if (! is_array($slide)) {
                continue;
            }
            $out = [];
            self::copyText($slide, $out, 'overline', 'headline', 'sub', 'cta_label');
            $out['cta_href'] = self::str($slide['cta_href'] ?? null);
            $out['media_id'] = isset($slide['media_id']) && is_numeric($slide['media_id']) ? (int) $slide['media_id'] : null;
            self::copyText($slide, $out, 'alt');
            $slides[] = $out;
        }

        return ['show_age_pills' => self::bool($content['show_age_pills'] ?? null, true), 'slides' => $slides];
    }
}
