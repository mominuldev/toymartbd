<?php

declare(strict_types=1);

namespace App\Support\Locale;

use App\Support\Money;
use DateTimeInterface;
use IntlDateFormatter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Locale-aware display formatting with Western digits in BOTH locales (CLAUDE.md rule 36).
 *
 * Numbers and money are formatted by hand on decimal strings, not through
 * NumberFormatter: ICU's `bn` locale yields Bengali digits AND lakh grouping
 * (১২,৫০,০০০), and even `bn@numbers=latn` keeps the lakh grouping (12,50,000).
 * The design system wants three-digit grouping everywhere
 * (docs/_design/02-primitives-spec.md §19.3), so number output is identical
 * in `en` and `bn`; only month names and day periods are localised.
 *
 * Dates go through IntlDateFormatter with `@numbers=latn`, then through a
 * Bengali→Western digit safety net in case an ICU build ignores the keyword.
 *
 * Examples (docs/_design/05-content-and-assets-spec.md §0.4):
 *   money('2450')        ৳2,450          money('0.45')    ৳0.45
 *   money('-450')        −৳450 (U+2212)  price('2450.50') ৳2,451
 *   smsMoney('2450.00')  BDT 2450
 *   date(…, 'en')        8 Nov 2026      date(…, 'bn')    8 নভেম্বর 2026
 *   time(…, 'en')        4:30 PM         time(…, 'bn')    বিকাল 4:30
 */
final class Formatter
{
    public const TAKA = '৳';

    private const MINUS = "\u{2212}";

    private const BENGALI_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** @var array<string, array{date: string, time: string}> ICU patterns per locale */
    private const PATTERNS = [
        'en' => ['date' => 'd MMM y', 'time' => 'h:mm a'],
        'bn' => ['date' => 'd MMMM y', 'time' => 'B h:mm'],
    ];

    /**
     * Money for display: `৳2,450`, decimals only when non-zero (`৳2,450.50`, `৳0.45`).
     * Never rounds — use price() for a customer-facing retail price.
     */
    public static function money(Money|string|int $amount, ?string $locale = null): string
    {
        [$negative, $integer, $fraction] = self::split(self::decimal($amount));

        $body = self::group($integer).($fraction === '00' ? '' : '.'.$fraction);

        return self::signed($negative, $body);
    }

    /**
     * A retail price: whole taka, rounded half away from zero (`৳2,450.50` → `৳2,451`).
     * docs/_design/02-primitives-spec.md §19.3 — "Paisa does not exist in BD retail pricing".
     */
    public static function price(Money|string|int $amount, ?string $locale = null): string
    {
        [$negative, $integer, $fraction] = self::split(self::decimal($amount));

        if ((int) $fraction >= 50) {
            $integer = bcadd($integer, '1', 0);
        }

        return self::signed($negative && $integer !== '0', self::group($integer));
    }

    /**
     * Money inside an SMS body: `BDT 2450`. `৳` is outside GSM-7 and would force the
     * whole message into UCS-2 (docs/_design/05-content-and-assets-spec.md §0.4).
     */
    public static function smsMoney(Money|string|int $amount): string
    {
        [$negative, $integer, $fraction] = self::split(self::decimal($amount));

        return 'BDT '.($negative ? '-' : '').$integer.($fraction === '00' ? '' : '.'.$fraction);
    }

    /** A grouped number with a fixed number of decimals: number('1250000.5', 2) → 1,250,000.50. */
    public static function number(int|string $value, int $decimals = 0, ?string $locale = null): string
    {
        $decimal = is_int($value) ? (string) $value : trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $decimal) !== 1) {
            throw new InvalidArgumentException("Not a number: [{$decimal}]");
        }

        $rounded = self::roundHalfAwayFromZero($decimal, max(0, $decimals));
        $negative = str_starts_with($rounded, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($rounded, '-')), 2, '');

        $isZero = trim($integer.$fraction, '0') === '';

        return ($negative && ! $isZero ? '-' : '').self::group($integer).($fraction === '' ? '' : '.'.$fraction);
    }

    /** A count of items: quantity(1200) → 1,200. */
    public static function quantity(int $quantity, ?string $locale = null): string
    {
        return self::number($quantity, 0, $locale);
    }

    /** A calendar date in display time: `8 Nov 2026` / `8 নভেম্বর 2026`. */
    public static function date(DateTimeInterface $instant, ?string $locale = null): string
    {
        return self::intl($instant, self::patterns($locale)['date'], $locale);
    }

    /** A time of day in display time: `4:30 PM` / `বিকাল 4:30`. */
    public static function time(DateTimeInterface $instant, ?string $locale = null): string
    {
        return self::intl($instant, self::patterns($locale)['time'], $locale);
    }

    /** `8 Nov 2026, 4:30 PM` / `8 নভেম্বর 2026, বিকাল 4:30`. */
    public static function dateTime(DateTimeInterface $instant, ?string $locale = null): string
    {
        return self::date($instant, $locale).', '.self::time($instant, $locale);
    }

    /** Replace any Bengali digits (০–৯) with Western digits (0–9). */
    public static function westernDigits(string $text): string
    {
        return str_replace(self::BENGALI_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $text);
    }

    public static function displayTimezone(): string
    {
        $zone = config('app.display_timezone', 'Asia/Dhaka');

        return is_string($zone) && $zone !== '' ? $zone : 'Asia/Dhaka';
    }

    private static function intl(DateTimeInterface $instant, string $pattern, ?string $locale): string
    {
        $formatter = new IntlDateFormatter(
            self::locale($locale).'@numbers=latn',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            self::displayTimezone(),
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        $formatted = $formatter->format($instant);

        if ($formatted === false) {
            throw new RuntimeException('Date formatting failed: '.$formatter->getErrorMessage());
        }

        return self::westernDigits($formatted);
    }

    /** @return array{date: string, time: string} */
    private static function patterns(?string $locale): array
    {
        return self::PATTERNS[self::locale($locale)];
    }

    /** The requested locale, else the app locale, else `en` — always a key of PATTERNS. */
    private static function locale(?string $locale): string
    {
        $candidate = $locale ?? app()->getLocale();
        $primary = strtolower(explode('-', str_replace('_', '-', $candidate))[0]);

        return array_key_exists($primary, self::PATTERNS) ? $primary : 'en';
    }

    private static function decimal(Money|string|int $amount): string
    {
        return $amount instanceof Money ? $amount->toDecimal() : Money::of($amount)->toDecimal();
    }

    /** @return array{0: bool, 1: string, 2: string} negative, integer digits, two fraction digits */
    private static function split(string $decimal): array
    {
        $negative = str_starts_with($decimal, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, '00');

        return [$negative, $integer, str_pad($fraction, 2, '0')];
    }

    private static function signed(bool $negative, string $body): string
    {
        return ($negative ? self::MINUS : '').self::TAKA.$body;
    }

    /** Three-digit comma grouping on an unsigned digit string: 1250000 → 1,250,000. */
    private static function group(string $digits): string
    {
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return '0';
        }

        return strrev(implode(',', str_split(strrev($digits), 3)));
    }

    private static function roundHalfAwayFromZero(string $decimal, int $scale): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = str_starts_with($decimal, '-')
            ? bcsub($decimal, $half, $scale + 1)
            : bcadd($decimal, $half, $scale + 1);

        return bcadd($adjusted, '0', $scale); // bcadd truncates toward zero
    }
}
