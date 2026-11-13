<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Support\Money;
use Normalizer;
use NumberFormatter;

/**
 * The total in words, as Bangladeshi invoices write it — Indian grouping (lakh, crore):
 *
 *   english  "Taka one lakh twenty-five thousand three hundred and fifty paisa only"
 *            (ICU `en_IN` spellout)
 *   bangla   "এক লক্ষ পঁচিশ হাজার তিনশত টাকা পঞ্চাশ পয়সা মাত্র" (P9: table-driven)
 *
 * Why Bangla is a table, not ICU: ICU 77's `bn` locale has no spellout rules — it falls back
 * to English ("one million" for 10,00,000; verified in P9 batch 1), and an ICU without the
 * data on CI would print English silently. Bangla 0–99 are irregular (একুশ, বাইশ, … নিরানব্বই),
 * so they are a 100-entry table; hundreds join as "…শত", then হাজার, লক্ষ, কোটি; a crore count
 * above 99 is spelt recursively. Words are editorial copy, so Bangla words are right on a
 * Bangla document; every figure elsewhere stays in Western digits (rule 36).
 *
 * NATIVE-SPEAKER REVIEW: the spellings follow the Bangla Academy forms (উনত্রিশ, একচল্লিশ,
 * উনআশি, শত/লক্ষ rather than শো/লাখ) — the owner or a native reviewer should confirm them
 * before launch (P9 batch 1 open item).
 */
final class AmountInWords
{
    /** 0–99, Bangla Academy spellings. */
    private const array BN = [
        'শূন্য', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
        'দশ', 'এগারো', 'বারো', 'তেরো', 'চৌদ্দ', 'পনেরো', 'ষোলো', 'সতেরো', 'আঠারো', 'উনিশ',
        'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'উনত্রিশ',
        'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'উনচল্লিশ',
        'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'উনপঞ্চাশ',
        'পঞ্চাশ', 'একান্ন', 'বায়ান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'উনষাট',
        'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'উনসত্তর',
        'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'উনআশি',
        'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'উননব্বই',
        'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
    ];

    public static function english(Money $amount): string
    {
        $poisha = abs($amount->toPoisha());
        $taka = intdiv($poisha, 100);
        $paisa = $poisha % 100;
        $speller = new NumberFormatter('en_IN', NumberFormatter::SPELLOUT);

        $words = 'Taka '.self::spell($speller, $taka);
        if ($paisa > 0) {
            $words .= ' and '.self::spell($speller, $paisa).' paisa';
        }

        return ucfirst($words.' only');
    }

    /** "এক লক্ষ পঁচিশ হাজার তিনশত টাকা পঞ্চাশ পয়সা মাত্র" — NFC-normalised. */
    public static function bangla(Money $amount): string
    {
        $poisha = abs($amount->toPoisha());
        $taka = intdiv($poisha, 100);
        $paisa = $poisha % 100;

        $words = self::banglaNumber($taka).' টাকা';
        if ($paisa > 0) {
            $words .= ' '.self::BN[$paisa].' পয়সা';
        }
        $words .= ' মাত্র';

        $normalised = Normalizer::normalize($words, Normalizer::FORM_C);

        return is_string($normalised) ? $normalised : $words;
    }

    /** A non-negative whole number in Bangla words, Indian grouping. */
    public static function banglaNumber(int $n): string
    {
        if ($n < 100) {
            return self::BN[$n];
        }

        $parts = [];
        $crore = intdiv($n, 10_000_000);
        $n %= 10_000_000;
        if ($crore > 0) {
            $parts[] = self::banglaNumber($crore).' কোটি';
        }
        $lakh = intdiv($n, 100_000);
        $n %= 100_000;
        if ($lakh > 0) {
            $parts[] = self::BN[$lakh].' লক্ষ';
        }
        $thousand = intdiv($n, 1_000);
        $n %= 1_000;
        if ($thousand > 0) {
            $parts[] = self::BN[$thousand].' হাজার';
        }
        $hundred = intdiv($n, 100);
        $n %= 100;
        if ($hundred > 0) {
            $parts[] = self::BN[$hundred].'শত';
        }
        if ($n > 0) {
            $parts[] = self::BN[$n];
        }

        return implode(' ', $parts);
    }

    private static function spell(NumberFormatter $speller, int $value): string
    {
        $text = $speller->format($value);

        return is_string($text) ? $text : (string) $value;
    }
}
