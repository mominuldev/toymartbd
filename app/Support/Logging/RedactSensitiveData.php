<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stringable;
use Throwable;

/**
 * Keeps credentials, full phone numbers and full addresses out of the logs and out
 * of Sentry (CLAUDE.md rule 20).
 *
 *  - Any e-mail address is masked to `r***@example.com` (P10).
 *  - Any Bangladeshi mobile number anywhere in a string — the message, a context
 *    value, an extra value — is masked to `017****5678`, the same form as
 *    PhoneNumber::masked().
 *  - Values under a sensitive key (password, token, secret, api_key, authorization,
 *    cookie, credentials, address…) are replaced with `[redacted]`, at any depth.
 *    Keys match case-insensitively and with `-`/`_` treated alike, so a
 *    `X-Api-Key` header and an `api_key` field are both caught.
 *
 * Runs as a handler-level Monolog processor on every channel (see RedactLogs), and
 * its static helpers back the Sentry before_send scrubber.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const string REDACTED = '[redacted]';

    /**
     * A key is sensitive when it equals one of these, or contains one of the
     * SENSITIVE_KEY_FRAGMENTS below. Compared after lower-casing and `-` → `_`.
     */
    private const array SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'proxy_authorization',
        'cookie',
        'set_cookie',
        'credentials',
        'address',
        'address_line',
        'address_line_1',
        'address_line_2',
        'php_auth_pw',
        // One-time passcodes (P10): a login OTP in a log is a live credential for minutes.
        'otp',
        'otp_code',
        'one_time_password',
        'verification_code',
    ];

    /** Substrings that make any key sensitive: `access_token`, `client_secret`, `x_xsrf_token`, `shipping_address`. */
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'token',
        'secret',
        'api_key',
        'credential',
        'address_line',
        '_address',
        'authorization',
        'cookie',
        '_otp',
        'otp_',
    ];

    /**
     * Secrets embedded in free text: a raw JSON body (`"password":"…"`), a query or
     * form string (`token=…`), a bearer token. A request body or a serialised
     * Request in a stack frame arrives as one string, past the key-based redaction.
     */
    private const string SECRET_WORDS = '[A-Za-z0-9_\-]*(?:password|token|secret|api[_\-]?key|authorization|cookie|credential|address_line|otp)[A-Za-z0-9_\-]*';

    /**
     * 013–019 mobile numbers with a +880 / 880 / 0 prefix, not glued to other digits.
     * Group 1 is the ten digits after the trunk prefix.
     */
    private const string PHONE_PATTERN = '/(?<!\d)(?:\+?880|0)(1[3-9]\d{8})(?!\d)/';

    /** An e-mail address; the first character of the local part and the domain survive (P10). */
    private const string EMAIL_PATTERN = '/(?<![A-Za-z0-9._%+\-])([A-Za-z0-9])[A-Za-z0-9._%+\-]*@([A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,})/';

    private const int MAX_DEPTH = 10;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::scrubText($record->message),
            context: self::redactArray($record->context),
            extra: self::redactArray($record->extra),
        );
    }

    /** `+8801712345678` → `017****5678`, anywhere in the string. */
    public static function maskPhones(string $value): string
    {
        return preg_replace_callback(
            self::PHONE_PATTERN,
            static fn (array $m): string => '0'.substr($m[1], 0, 2).'****'.substr($m[1], -4),
            $value,
        ) ?? $value;
    }

    /** `rahela.khatun@example.com` → `r***@example.com`, anywhere in the string. */
    public static function maskEmails(string $value): string
    {
        return preg_replace(self::EMAIL_PATTERN, '$1***@$2', $value) ?? $value;
    }

    /** Phones and e-mails masked, plus secrets inside JSON / query strings / bearer headers. */
    public static function scrubText(string $value): string
    {
        $value = self::maskEmails(self::maskPhones($value));

        $value = preg_replace(
            [
                '/("'.self::SECRET_WORDS.'"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i',
                '/(\\\\"'.self::SECRET_WORDS.'\\\\"\s*:\s*)\\\\"(?:[^\\\\]|\\\\[^"])*\\\\"/i',
                '/(?<![A-Za-z0-9_\-])('.self::SECRET_WORDS.'=)[^&\s"\']+/i',
                '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]+/',
            ],
            [
                '$1"'.self::REDACTED.'"',
                '$1\\\\"'.self::REDACTED.'\\\\"',
                '$1'.self::REDACTED,
                '$1 '.self::REDACTED,
            ],
            $value,
        ) ?? $value;

        return $value;
    }

    public static function isSensitiveKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $normalised = str_replace('-', '_', strtolower($key));

        if (in_array($normalised, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redactArray(array $data, int $depth = 0): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $clean[$key] = self::isSensitiveKey($key) ? self::REDACTED : self::redactValue($value, $depth + 1);
        }

        return $clean;
    }

    public static function redactValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return $value;
        }

        return match (true) {
            is_string($value) => self::scrubText($value),
            is_array($value) => self::redactArray($value, $depth),
            // Throwables stay objects so formatters can still render the trace; the
            // RedactingFormatter masks phones in the rendered text instead.
            $value instanceof Throwable => $value,
            $value instanceof Stringable => self::scrubText((string) $value),
            default => $value,
        };
    }
}
