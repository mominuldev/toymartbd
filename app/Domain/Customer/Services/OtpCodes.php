<?php

declare(strict_types=1);

namespace App\Domain\Customer\Services;

use App\Support\PhoneNumber;
use RuntimeException;

/**
 * The OTP policy and its hashing (P4-STO-11). Codes are 6 random digits, valid for five
 * minutes, five wrong guesses burn the code. A phone may receive one code a minute and at
 * most five an hour — on top of the route limiters, so the cap holds even when requests
 * arrive from many IPs through the Next.js server.
 *
 * Phones and codes are stored as HMAC-SHA256 under the app key, so a leaked table reveals
 * neither the numbers that signed in nor a usable code.
 */
final class OtpCodes
{
    public const int LENGTH = 6;

    public const int TTL_SECONDS = 300;

    public const int MAX_ATTEMPTS = 5;

    public const int RESEND_COOLDOWN_SECONDS = 60;

    public const int MAX_SENDS_PER_HOUR = 5;

    public const string PURPOSE_LOGIN = 'login';

    public function generate(): string
    {
        return str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    public function phoneHash(PhoneNumber $phone): string
    {
        return $this->hmac('phone|'.$phone->e164());
    }

    /** The code is bound to the phone, so one hash cannot be replayed for another number. */
    public function codeHash(PhoneNumber $phone, string $code): string
    {
        return $this->hmac('code|'.$phone->e164().'|'.$code);
    }

    public function ipHash(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : $this->hmac('ip|'.$ip);
    }

    public function matches(PhoneNumber $phone, string $code, string $hash): bool
    {
        return hash_equals($hash, $this->codeHash($phone, $code));
    }

    private function hmac(string $value): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY is not set.');
        }

        return hash_hmac('sha256', $value, $key);
    }
}
