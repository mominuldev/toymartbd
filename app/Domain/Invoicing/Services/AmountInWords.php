<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Support\Money;
use NumberFormatter;

/**
 * "Taka two thousand four hundred fifty and fifty paisa only" — English, Indian grouping
 * (lakh, crore) as Bangladeshi invoices write it, via ICU `en_IN` spellout.
 *
 * English only, on purpose: ICU ships no Bangla spellout data (it falls back to English),
 * and a hand-written Bangla 0–99 word table is not something to guess. The Bangla template
 * leaves the line out until a reviewed table exists (open item, P8 handoff).
 */
final class AmountInWords
{
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

    private static function spell(NumberFormatter $speller, int $value): string
    {
        $text = $speller->format($value);

        return is_string($text) ? $text : (string) $value;
    }
}
