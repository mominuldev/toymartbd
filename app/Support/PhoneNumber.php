<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * A Bangladeshi mobile number (CLAUDE.md rule 30).
 *
 * Accepts +880 / 880 / 0 prefixes, spaces, dashes, dots, parentheses and Bengali
 * digits (০–৯), and normalises them all to one value. Only 013–019 are valid:
 * 010/011/012 and the 06x IoT series are rejected. libphonenumber is deliberately
 * not used — it accepts 011.
 *
 * Two things this class will not do:
 *  - infer the operator from the prefix (number portability exists since 2018);
 *  - print the full number by accident. There is no __toString; ask for e164(),
 *    national() or masked() explicitly, and never log the first two (rule 20).
 */
final readonly class PhoneNumber
{
    private const PATTERN = '/^(?:\+?880|0)1[3-9]\d{8}$/';

    private const BENGALI_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** @param string $national 11 digits, 01XXXXXXXXX */
    private function __construct(private string $national) {}

    public static function parse(string $input): self
    {
        $number = self::tryParse($input);

        if ($number === null) {
            // The input is not echoed back: it may be a real person's number.
            throw new InvalidArgumentException('Not a valid Bangladeshi mobile number (013–019).');
        }

        return $number;
    }

    public static function tryParse(string $input): ?self
    {
        $clean = str_replace(self::BENGALI_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $input);
        $clean = preg_replace('/[\s\-.()]/u', '', $clean) ?? '';

        if (preg_match(self::PATTERN, $clean) !== 1) {
            return null;
        }

        return new self('0'.substr($clean, -10));
    }

    public static function isValid(string $input): bool
    {
        return self::tryParse($input) !== null;
    }

    /** +8801XXXXXXXXX — the stored form. */
    public function e164(): string
    {
        return '+88'.$this->national;
    }

    /** 01XXXXXXXXX — what customers type and couriers expect. */
    public function national(): string
    {
        return $this->national;
    }

    /** 017****5678 — the only form staff list views and logs may show. */
    public function masked(): string
    {
        return substr($this->national, 0, 3).'****'.substr($this->national, -4);
    }

    /** Last N digits, for order tracking's "order number + phone digits" check (rule 22). */
    public function lastDigits(int $count = 4): string
    {
        return substr($this->national, -$count);
    }

    public function equals(self $other): bool
    {
        return $this->national === $other->national;
    }
}
