<?php

declare(strict_types=1);

namespace App\Domain\Seo\Services;

/**
 * Pixel widths of a search-result title and description, as Google's desktop result
 * sets them (SeoPanel 198:1123: "a pixel-width meter at Google's desktop metric, not
 * characters"). Google truncates by rendered width, so "60 characters" is a myth for
 * a title of Ms and Ws.
 *
 * An ESTIMATE: Arial advance widths (the Helvetica-compatible metrics, per 1000 em) at
 * 20 px for the title and 14 px for the description. Bangla glyphs use a flat estimate
 * (base letters 0.6 em, dependent vowel signs 0.3 em, virama and nasal marks 0) because
 * the shaped width depends on the font the browser picks. The limits are the commonly
 * cited desktop truncation points (580 px title, 920 px description); the mobile
 * description figure (680 px) is a rougher estimate. Google publishes none of them.
 */
final class SerpMeasure
{
    public const int TITLE_PX = 20;

    public const int DESCRIPTION_PX = 14;

    public const int TITLE_MAX = 580;

    public const int DESCRIPTION_MAX = 920;

    public const int DESCRIPTION_MOBILE_MAX = 680;

    /** Arial advance widths, 1000 units per em, for printable ASCII (32–126). */
    private const array ASCII = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, // space … /
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, // 0–9
        278, 278, 584, 584, 584, 556, 1015, // : ; < = > ? @
        667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, // A–Z
        278, 278, 278, 469, 556, 333, // [ \ ] ^ _ `
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, // a–z
        334, 260, 334, 584, // { | } ~
    ];

    /** @var array<string, int> */
    private const array EXTRA = [
        '—' => 1000, '–' => 556, '…' => 1000, '•' => 350, '·' => 278, '×' => 584, '৳' => 556,
        '’' => 222, '‘' => 222, '“' => 333, '”' => 333, '₹' => 556, '©' => 737, '®' => 737, '™' => 1000,
    ];

    public function width(string $text, int $fontPx): int
    {
        $units = 0;

        foreach (mb_str_split($text) as $char) {
            $units += self::units($char);
        }

        return (int) round($units * $fontPx / 1000);
    }

    /** @return array{px: int, chars: int, max: int, fits: bool} */
    public function title(string $text): array
    {
        $px = $this->width($text, self::TITLE_PX);

        return ['px' => $px, 'chars' => mb_strlen($text), 'max' => self::TITLE_MAX, 'fits' => $text !== '' && $px <= self::TITLE_MAX];
    }

    /** @return array{px: int, chars: int, max: int, fits: bool, fits_mobile: bool} */
    public function description(string $text): array
    {
        $px = $this->width($text, self::DESCRIPTION_PX);

        return [
            'px' => $px,
            'chars' => mb_strlen($text),
            'max' => self::DESCRIPTION_MAX,
            'fits' => $text !== '' && $px <= self::DESCRIPTION_MAX,
            'fits_mobile' => $text !== '' && $px <= self::DESCRIPTION_MOBILE_MAX,
        ];
    }

    private static function units(string $char): int
    {
        $code = mb_ord($char);

        if ($code >= 32 && $code <= 126) {
            return self::ASCII[$code - 32];
        }

        if (isset(self::EXTRA[$char])) {
            return self::EXTRA[$char];
        }

        // Bangla (U+0980–U+09FF).
        if ($code >= 0x0980 && $code <= 0x09FF) {
            return match (true) {
                $code === 0x0981, $code === 0x09BC, $code === 0x09CD, $code === 0x09D7 => 0, // chandrabindu, nukta, virama, au length mark
                $code >= 0x09BE && $code <= 0x09CC => 300, // dependent vowel signs
                default => 600,
            };
        }

        // Combining marks and zero-width joiners.
        if (($code >= 0x0300 && $code <= 0x036F) || ($code >= 0x200B && $code <= 0x200D)) {
            return 0;
        }

        // CJK and other full-width scripts.
        if ($code >= 0x2E80) {
            return 1000;
        }

        return 556;
    }
}
