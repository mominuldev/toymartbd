<?php

declare(strict_types=1);

namespace App\Domain\Content\Blocks\Schemas;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * One block type's JSON schema, enforced server-side. `rules()` are Laravel validation rules
 * for the content object (keys relative to it; the FormRequest prefixes them with `content.`).
 * `normalize()` runs on validated input before it is stored: it keeps only known keys, trims
 * text, turns empty strings into null and sanitises rich text, so every stored row has the
 * same shape. `defaults()` is the content of a freshly added block and must itself validate.
 *
 * Bilingual copy is a pair of keys, `x` (English, required where the block needs copy) and
 * `x_bn` (Bangla, optional: the storefront falls back to English, never to an empty string).
 */
abstract class BlockSchema
{
    /** Relative storefront path or an https URL; never `//host`, `javascript:` or whitespace. */
    public const string HREF_PATTERN = '#^(?:/(?!/)[^\s]*|https://[^\s/]+[^\s]*)$#';

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    /** @return array<string, mixed> */
    abstract public function defaults(): array;

    /**
     * @param  array<string, mixed>  $content  validated input
     * @return array<string, mixed>
     */
    abstract public function normalize(array $content): array;

    /** @return array<string, list<mixed>> `key` and `key_bn` */
    protected static function text(string $key, int $max, bool $required = true): array
    {
        return [
            $key => [$required ? 'required' : 'nullable', 'string', 'max:'.$max],
            $key.'_bn' => ['nullable', 'string', 'max:'.$max],
        ];
    }

    /** @return list<mixed> */
    protected static function href(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:2048', 'regex:'.self::HREF_PATTERN];
    }

    protected static function imageMedia(): Exists
    {
        return Rule::exists('media', 'id')->where('kind', 'image');
    }

    /**
     * Copy both halves of a bilingual pair, trimmed; an empty value becomes null.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    protected static function copyText(array $from, array &$to, string ...$keys): void
    {
        foreach ($keys as $key) {
            $to[$key] = self::str($from[$key] ?? null);
            $to[$key.'_bn'] = self::str($from[$key.'_bn'] ?? null);
        }
    }

    /** The palette tints a chip, card or band can take (the storefront's `tile-*` / Smart Junior pastels). */
    public const array TONES = ['pink', 'green', 'blue', 'yellow', 'purple', 'orange'];

    /** @return list<mixed> */
    protected static function toneRule(): array
    {
        return ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::TONES)];
    }

    /** A tone from the palette, else `$default` (null: no tone). */
    protected static function tone(mixed $value, ?string $default): ?string
    {
        return is_string($value) && in_array($value, self::TONES, true) ? $value : $default;
    }

    /**
     * The Smart Junior section header (Figma "Section Header"): a coloured eyebrow chip, the
     * heading and a line under it. `title` is required; the rest is optional.
     *
     * @return array<string, mixed>
     */
    protected static function headerRules(int $titleMax = 120): array
    {
        return [
            ...self::text('eyebrow', 60, false),
            'eyebrow_tone' => self::toneRule(),
            ...self::text('title', $titleMax),
            ...self::text('sub', 240, false),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $out
     */
    protected static function copyHeader(array $content, array &$out, string $tone): void
    {
        self::copyText($content, $out, 'eyebrow');
        $out['eyebrow_tone'] = self::tone($content['eyebrow_tone'] ?? null, $tone);
        self::copyText($content, $out, 'title', 'sub');
    }

    /** Slide transition lengths, ms, and autoplay delays, seconds — fixed choices, so no editor can set an unusable value. */
    public const array SLIDER_SPEEDS = [300, 500, 800, 1200];

    public const array SLIDER_DELAYS = [3, 4, 5, 6, 8, 10];

    /**
     * Carousel behaviour for a block the storefront shows as a slider (hero, product rail in
     * `slider` layout, flash sale, video reel): `slider.loop`, `slider.autoplay`, `slider.delay`
     * (seconds between slides while autoplaying) and `slider.speed` (transition, ms). Autoplay
     * is off by default; the storefront pauses it on hover/focus, gives a pause control and never
     * autoplays under prefers-reduced-motion (WCAG 2.2.2).
     *
     * @return array<string, mixed>
     */
    protected static function sliderRules(): array
    {
        return [
            'slider' => ['sometimes', 'nullable', 'array'],
            'slider.loop' => ['sometimes', 'boolean'],
            'slider.autoplay' => ['sometimes', 'boolean'],
            'slider.delay' => ['sometimes', 'integer', 'in:'.implode(',', self::SLIDER_DELAYS)],
            'slider.speed' => ['sometimes', 'integer', 'in:'.implode(',', self::SLIDER_SPEEDS)],
        ];
    }

    /** @return array{loop: bool, autoplay: bool, delay: int, speed: int} */
    public static function sliderDefaults(): array
    {
        return ['loop' => false, 'autoplay' => false, 'delay' => 5, 'speed' => 500];
    }

    /** @return array{loop: bool, autoplay: bool, delay: int, speed: int} */
    protected static function slider(mixed $value): array
    {
        $in = is_array($value) ? $value : [];
        $d = self::sliderDefaults();
        $delay = self::int($in['delay'] ?? null, $d['delay']);
        $speed = self::int($in['speed'] ?? null, $d['speed']);

        return [
            'loop' => self::bool($in['loop'] ?? null, $d['loop']),
            'autoplay' => self::bool($in['autoplay'] ?? null, $d['autoplay']),
            'delay' => in_array($delay, self::SLIDER_DELAYS, true) ? $delay : $d['delay'],
            'speed' => in_array($speed, self::SLIDER_SPEEDS, true) ? $speed : $d['speed'],
        ];
    }

    /**
     * Prefix every key of a rule set (`items.*.` for a list item's fields).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected static function prefixed(string $prefix, array $rules): array
    {
        $out = [];
        foreach ($rules as $key => $rule) {
            $out[$prefix.$key] = $rule;
        }

        return $out;
    }

    /**
     * The list items of `$content[$key]` that are arrays, at most `$max`.
     *
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    protected static function items(array $content, string $key, int $max): array
    {
        $out = [];
        foreach (is_array($content[$key] ?? null) ? $content[$key] : [] as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return array_slice($out, 0, $max);
    }

    protected static function mediaId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    protected static function bool(mixed $value, bool $default): bool
    {
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<int> */
    protected static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(intval(...), array_filter($value, is_numeric(...)))));
    }
}
