<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use InvalidArgumentException;

/**
 * EAN-13 arithmetic (GS1 General Specifications §7.9): the check digit is 10 minus the
 * weighted sum (1, 3, 1, 3 … from the left over the first 12 digits) mod 10, mod 10.
 * `2000000000015` is the first in-store code with the default prefix `20`.
 */
final class Ean13
{
    /** @param  string  $twelve  exactly 12 digits */
    public static function checkDigit(string $twelve): int
    {
        if (preg_match('/^\d{12}$/', $twelve) !== 1) {
            throw new InvalidArgumentException('An EAN-13 check digit needs exactly 12 digits.');
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $twelve[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /** 13 digits whose last one is the right check digit. */
    public static function isValid(?string $code): bool
    {
        return $code !== null
            && preg_match('/^\d{13}$/', $code) === 1
            && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /** Prefix + `$number` zero-padded to fill 12 digits + the check digit. */
    public static function build(string $prefix, int $number): string
    {
        if ($number < 1 || $number > self::capacity($prefix)) {
            throw new InvalidArgumentException("Running number {$number} does not fit after prefix {$prefix}.");
        }

        $twelve = $prefix.str_pad((string) $number, 12 - strlen($prefix), '0', STR_PAD_LEFT);

        return $twelve.self::checkDigit($twelve);
    }

    /** The largest running number that fits after `$prefix`. */
    public static function capacity(string $prefix): int
    {
        return 10 ** (12 - strlen($prefix)) - 1;
    }
}
