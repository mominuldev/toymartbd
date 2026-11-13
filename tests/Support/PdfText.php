<?php

declare(strict_types=1);

namespace Tests\Support;

use Normalizer;

/**
 * Minimal text extraction for the PDFs mPDF writes (P8 invoices): every content stream is
 * inflated and the strings shown by `Tj` / `TJ` are decoded. mPDF embeds fonts as
 * Identity-H with an identity ToUnicode CMap, so a shown string is UTF-16BE: the code IS
 * the Unicode code point (shaped Bangla conjuncts are private-use glyphs).
 *
 * What a PDF reader can extract is what this returns: Latin text exactly; Bangla in VISUAL
 * glyph order (a pre-base vowel sign such as ি comes before its consonant) with conjuncts
 * as private-use characters — mPDF writes no /ActualText. {@see self::banglaLetters()}
 * reduces Bangla to its consonants and independent vowels so a test can assert the name
 * came through letter by letter, in order.
 */
final class PdfText
{
    public static function extract(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);
        $text = '';
        foreach ($streams[1] as $raw) {
            $data = @gzuncompress($raw);
            $data = $data === false ? $raw : $data;
            if (! str_contains($data, 'BT')) {
                continue;
            }
            preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|\bET\b/s', $data, $tokens);
            foreach ($tokens[0] as $token) {
                if ($token === 'ET') {
                    $text .= "\n";

                    continue;
                }
                $text .= self::decode(self::unescape(substr($token, 1, -1)));
            }
        }

        return $text;
    }

    /** Bangla consonants and independent vowels only (no signs, no hasant, no PUA, no spaces). */
    public static function banglaLetters(string $text): string
    {
        // NFD first: ড় / ঢ় / য় may arrive precomposed (U+09DC…) or as letter + nukta.
        $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

        return (string) preg_replace('/[^\x{0985}-\x{09B9}\x{09CE}]/u', '', is_string($decomposed) ? $decomposed : $text);
    }

    private static function decode(string $bytes): string
    {
        if (strlen($bytes) % 2 !== 0) {
            return $bytes;
        }
        $out = '';
        foreach (str_split($bytes, 2) as $pair) {
            $code = (ord($pair[0]) << 8) | ord($pair[1]);
            $char = mb_chr($code, 'UTF-8');
            $out .= $char === false ? '' : $char; // never `?:` — "0" is falsy
        }

        return $out;
    }

    private static function unescape(string $s): string
    {
        return (string) preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', static function (array $m): string {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                '(' => '(', ')' => ')', '\\' => '\\',
                default => chr((int) octdec($m[1])),
            };
        }, $s);
    }
}
