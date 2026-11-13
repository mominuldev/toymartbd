<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Support;

use InvalidArgumentException;

/**
 * Turns a money decimal STRING ("1999.90") into the JSON number a provider expects,
 * without float drift.
 *
 * Money never lives in a float in this codebase (rule 6). Both providers, however, want a
 * JSON number for `value` / `price`. PHP's json_encode uses serialize_precision = -1, i.e.
 * the SHORTEST representation that round-trips, so (float) "1999.90" is encoded as exactly
 * 1999.9 — the same decimal, no 1999.8999999999. This class refuses any input where that
 * would not hold (more than 15 significant digits), rather than silently sending a
 * different amount.
 */
final class DecimalNumber
{
    public static function toJsonNumber(string $decimal): int|float
    {
        $decimal = trim($decimal);

        if (preg_match('/^-?\d+(?:\.\d+)?$/', $decimal) !== 1) {
            throw new InvalidArgumentException('Not a decimal string.');
        }

        if (! str_contains($decimal, '.')) {
            return (int) $decimal;
        }

        $float = (float) $decimal;

        // Round-trip check: the encoded number must equal the input decimal, trailing
        // zeros aside. Fails only beyond ~15 significant digits — never for a BDT amount.
        $expected = rtrim(rtrim($decimal, '0'), '.');
        $expected = preg_replace('/^(-?)0+(?=\d)/', '$1', $expected) ?? $expected;
        $encoded = json_encode($float, JSON_PRESERVE_ZERO_FRACTION);
        $encoded = is_string($encoded) ? preg_replace('/\.0$/', '', $encoded) : null;

        if ($encoded !== $expected && $expected !== '-0' && $expected !== '0') {
            throw new InvalidArgumentException('Decimal cannot be represented exactly as a JSON number.');
        }

        return $float;
    }

    public static function tryToJsonNumber(?string $decimal): int|float|null
    {
        if ($decimal === null || trim($decimal) === '') {
            return null;
        }

        try {
            return self::toJsonNumber($decimal);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
