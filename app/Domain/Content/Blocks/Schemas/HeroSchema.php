<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

/**
 * Hero / banner carousel — Smart Junior "Vivid" hero (Figma 2b6PVOOapPbcaKYIdHWZup: desktop 34:5661,
 * tablet 34:6015, mobile 34:5884). One to five slides; the first is the page's LCP element. A slide
 * without `media_id` uses the built-in hero art. Each slide has an optional `accent` (words of the
 * headline drawn in the sun colour) and an optional second, outline button (`cta2_*`).
 *
 * Beside the slides: up to two `promos` (the mini promo cards — overline, title, button, image on a
 * pastel `tone`), and an optional `proof` row under the first slide's copy (up to three avatar
 * images, a star `rating` and a `label`). Both are editor-entered: the storefront draws nothing
 * the block does not carry, so no business figure is ever invented. `show_age_pills` adds the age
 * chooser under the first slide's copy when there is no proof row.
 */
final class HeroSchema extends BlockSchema
{
    public const int MAX_SLIDES = 5;

    public const int MAX_PROMOS = 2;

    public const int MAX_AVATARS = 3;

    /** The pastel tints a promo card can sit on (the storefront's `tile-*` tokens). */
    public const array TONES = ['pink', 'green', 'blue', 'yellow', 'purple', 'orange'];

    public function rules(): array
    {
        $rules = [
            'show_age_pills' => ['sometimes', 'boolean'],
            'slides' => ['required', 'array', 'min:1', 'max:'.self::MAX_SLIDES],
            'slides.*' => ['required', 'array'],
            ...$this->slide('slides.*.'),
            'promos' => ['sometimes', 'array', 'max:'.self::MAX_PROMOS],
            'promos.*' => ['required', 'array'],
            ...$this->promo('promos.*.'),
            'proof' => ['sometimes', 'nullable', 'array'],
            'proof.rating' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'proof.media_ids' => ['sometimes', 'array', 'max:'.self::MAX_AVATARS],
            'proof.media_ids.*' => ['integer', 'distinct', self::imageMedia()],
        ];
        foreach (self::text('label', 80, false) as $key => $rule) {
            $rules['proof.'.$key] = $rule;
        }

        return $rules;
    }

    /** @return array<string, mixed> */
    private function slide(string $p): array
    {
        $rules = [];
        foreach ([...self::text('overline', 60, false), ...self::text('headline', 120), ...self::text('accent', 60, false), ...self::text('sub', 240, false), ...self::text('cta_label', 40, false), ...self::text('cta2_label', 40, false), ...self::text('alt', 200, false)] as $key => $rule) {
            $rules[$p.$key] = $rule;
        }
        $rules[$p.'cta_href'] = [...self::href(), 'required_with:'.$p.'cta_label'];
        $rules[$p.'cta2_href'] = [...self::href(), 'required_with:'.$p.'cta2_label'];
        $rules[$p.'media_id'] = ['nullable', 'integer', self::imageMedia()];

        return $rules;
    }

    /** @return array<string, mixed> */
    private function promo(string $p): array
    {
        $rules = [];
        foreach ([...self::text('overline', 40, false), ...self::text('title', 80), ...self::text('cta_label', 40, false), ...self::text('alt', 200, false)] as $key => $rule) {
            $rules[$p.$key] = $rule;
        }
        $rules[$p.'cta_href'] = [...self::href(), 'required_with:'.$p.'cta_label'];
        $rules[$p.'media_id'] = ['nullable', 'integer', self::imageMedia()];
        $rules[$p.'tone'] = ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::TONES)];

        return $rules;
    }

    public function defaults(): array
    {
        return [
            'show_age_pills' => true,
            'slides' => [[
                'overline' => 'Shop by age', 'overline_bn' => 'বয়স অনুযায়ী খেলনা',
                'headline' => 'Tell us the age. See the right toys.', 'headline_bn' => 'বয়স বলুন, সঠিক খেলনা দেখুন',
                'accent' => null, 'accent_bn' => null,
                'sub' => 'Toys for 0–12 years. Age, price and stock stated on every one.', 'sub_bn' => '0–12 বছরের খেলনা। প্রতিটিতে বয়স, দাম আর স্টক পরিষ্কার লেখা।',
                'cta_label' => 'Browse all toys', 'cta_label_bn' => 'সব খেলনা দেখুন', 'cta_href' => '/toys',
                'cta2_label' => null, 'cta2_label_bn' => null, 'cta2_href' => null,
                'media_id' => null, 'alt' => null, 'alt_bn' => null,
            ]],
            'promos' => [],
            'proof' => null,
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
            self::copyText($slide, $out, 'overline', 'headline', 'accent', 'sub', 'cta_label');
            $out['cta_href'] = self::str($slide['cta_href'] ?? null);
            self::copyText($slide, $out, 'cta2_label');
            $out['cta2_href'] = self::str($slide['cta2_href'] ?? null);
            $out['media_id'] = self::mediaId($slide['media_id'] ?? null);
            self::copyText($slide, $out, 'alt');
            $slides[] = $out;
        }

        $promos = [];
        foreach (is_array($content['promos'] ?? null) ? $content['promos'] : [] as $promo) {
            if (! is_array($promo)) {
                continue;
            }
            $out = [];
            self::copyText($promo, $out, 'overline', 'title', 'cta_label');
            $out['cta_href'] = self::str($promo['cta_href'] ?? null);
            $out['media_id'] = self::mediaId($promo['media_id'] ?? null);
            self::copyText($promo, $out, 'alt');
            $tone = self::str($promo['tone'] ?? null);
            $out['tone'] = in_array($tone, self::TONES, true) ? $tone : self::TONES[count($promos) % count(self::TONES)];
            $promos[] = $out;
        }

        return [
            'show_age_pills' => self::bool($content['show_age_pills'] ?? null, true),
            'slides' => $slides,
            'promos' => array_slice($promos, 0, self::MAX_PROMOS),
            'proof' => self::proof($content['proof'] ?? null),
        ];
    }

    /** @return array<string, mixed>|null Null when the row would show nothing. */
    private static function proof(mixed $proof): ?array
    {
        if (! is_array($proof)) {
            return null;
        }
        $out = [];
        self::copyText($proof, $out, 'label');
        $out['rating'] = is_numeric($proof['rating'] ?? null) ? round(max(0.0, min(5.0, (float) $proof['rating'])), 1) : null;
        $ids = [];
        foreach (is_array($proof['media_ids'] ?? null) ? $proof['media_ids'] : [] as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $out['media_ids'] = array_slice(array_values(array_unique($ids)), 0, self::MAX_AVATARS);

        return $out['label'] === null && $out['rating'] === null ? null : $out;
    }

    private static function mediaId(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
