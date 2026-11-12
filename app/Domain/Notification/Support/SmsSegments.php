<?php

declare(strict_types=1);

namespace App\Domain\Notification\Support;

/**
 * SMS segment arithmetic (P4-I18N-03, CLAUDE.md rule 39).
 *
 * GSM-7: 160 septets in one segment, 153 per segment when concatenated (the UDH eats 7).
 * The extension-table characters (^ { } \ [ ~ ] | €) cost two septets each.
 * UCS-2: any character outside GSM-7 — every Bangla letter — switches the whole message
 * to UCS-2: 70 characters in one segment, 67 per segment when concatenated. Characters
 * outside the BMP (emoji) take two UTF-16 code units.
 */
final class SmsSegments
{
    public const GSM7_SINGLE = 160;

    public const GSM7_MULTI = 153;

    public const UCS2_SINGLE = 70;

    public const UCS2_MULTI = 67;

    private const GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM7_EXTENSION = "^{}\\[~]|€\f";

    public static function encoding(string $body): string
    {
        return self::isGsm7($body) ? 'gsm7' : 'ucs2';
    }

    public static function isGsm7(string $body): bool
    {
        foreach (mb_str_split($body) as $char) {
            if (! str_contains(self::GSM7_BASIC, $char) && ! str_contains(self::GSM7_EXTENSION, $char)) {
                return false;
            }
        }

        return true;
    }

    /** Billing length in the message's own encoding's units (septets or UTF-16 code units). */
    public static function units(string $body): int
    {
        if ($body === '') {
            return 0;
        }

        if (self::isGsm7($body)) {
            $units = 0;
            foreach (mb_str_split($body) as $char) {
                $units += str_contains(self::GSM7_EXTENSION, $char) ? 2 : 1;
            }

            return $units;
        }

        return intdiv(strlen((string) mb_convert_encoding($body, 'UTF-16BE', 'UTF-8')), 2);
    }

    public static function count(string $body): int
    {
        $units = self::units($body);

        if ($units === 0) {
            return 0;
        }

        [$single, $multi] = self::isGsm7($body)
            ? [self::GSM7_SINGLE, self::GSM7_MULTI]
            : [self::UCS2_SINGLE, self::UCS2_MULTI];

        return $units <= $single ? 1 : (int) ceil($units / $multi);
    }
}
