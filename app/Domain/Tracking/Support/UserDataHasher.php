<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Support;

use App\Support\PhoneNumber;

/**
 * Normalise-then-SHA-256 for customer information, per provider. The two providers'
 * rules DIFFER, so each has its own methods — never reuse one provider's hash for the
 * other. Every hash is lowercase hex SHA-256 of the UTF-8 bytes.
 *
 * Meta: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters
 * GA4:  https://developers.google.com/analytics/devguides/collection/ga4/uid-data
 */
final class UserDataHasher
{
    public static function sha256(string $normalised): string
    {
        return hash('sha256', $normalised);
    }

    /**
     * Phone digits in international form WITHOUT '+': 8801712345678. Accepts E.164
     * (+8801712345678), 8801712345678 or a local 01712345678 — all Bangladeshi numbers go
     * through App\Support\PhoneNumber (rule 30). Anything else: digits with leading zeros
     * removed (Meta: "Remove symbols, letters, and leading zeros"), or null.
     */
    public static function phoneDigits(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $parsed = PhoneNumber::tryParse($phone);
        if ($parsed !== null) {
            return ltrim($parsed->e164(), '+');
        }

        $digits = ltrim((string) preg_replace('/\D+/', '', $phone), '0');

        return $digits !== '' ? $digits : null;
    }

    // ── Meta Conversions API ────────────────────────────────────────────────────────

    /** em: trim, lowercase. */
    public static function metaEmail(?string $email): ?string
    {
        $email = $email !== null ? mb_strtolower(trim($email)) : '';

        return $email !== '' ? self::sha256($email) : null;
    }

    /** ph: digits only, country code included (880…), no '+', no leading zeros. */
    public static function metaPhone(?string $phone): ?string
    {
        $digits = self::phoneDigits($phone);

        return $digits !== null ? self::sha256($digits) : null;
    }

    /**
     * fn / ln: lowercase, no punctuation, UTF-8. Bangla script is kept as UTF-8 (Meta's own
     * examples hash Korean and accented names unchanged apart from case).
     */
    public static function metaName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = mb_strtolower(trim((string) preg_replace('/[\p{P}\p{S}]+/u', '', $name)));

        return $name !== '' ? self::sha256($name) : null;
    }

    /** ct: lowercase, no punctuation, no special characters, NO spaces ("newyork"). */
    public static function metaCity(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $city = mb_strtolower((string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', $city));

        return $city !== '' ? self::sha256($city) : null;
    }

    /** country: lowercase ISO 3166-1 alpha-2, hashed. */
    public static function metaCountry(string $iso2): string
    {
        return self::sha256(mb_strtolower(trim($iso2)));
    }

    /**
     * external_id: Meta recommends hashing; it gives no normalisation rule, only that it
     * must match the format sent through other channels. Trimmed, otherwise unchanged.
     */
    public static function metaExternalId(?string $id): ?string
    {
        $id = $id !== null ? trim($id) : '';

        return $id !== '' ? self::sha256($id) : null;
    }

    // ── GA4 Measurement Protocol user_data ──────────────────────────────────────────

    /** Lowercase, remove spaces; for gmail.com / googlemail.com remove '.' before '@'. */
    public static function ga4Email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower((string) preg_replace('/\s+/u', '', $email));
        if ($email === '') {
            return null;
        }

        $at = strrpos($email, '@');
        if ($at !== false) {
            $local = substr($email, 0, $at);
            $domain = substr($email, $at + 1);
            if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
                $email = str_replace('.', '', $local).'@'.$domain;
            }
        }

        return self::sha256($email);
    }

    /** E.164 WITH '+': remove all non-digits, add '+' prefix. */
    public static function ga4Phone(?string $phone): ?string
    {
        $digits = self::phoneDigits($phone);

        return $digits !== null ? self::sha256('+'.$digits) : null;
    }

    /** sha256_first_name / sha256_last_name: remove digits and symbols, lowercase, trim. */
    public static function ga4Name(?string $name): ?string
    {
        $name = self::ga4Text($name);

        return $name !== null ? self::sha256($name) : null;
    }

    /** city / region: remove digits and symbols, lowercase, trim. NOT hashed. */
    public static function ga4Text(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = mb_strtolower(trim((string) preg_replace('/[\p{N}\p{P}\p{S}]+/u', '', $text)));

        return $text !== '' ? $text : null;
    }
}
