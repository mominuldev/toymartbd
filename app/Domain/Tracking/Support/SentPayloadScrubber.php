<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Support;

/**
 * Last line of defence before a driver's "as sent" payload is stored in
 * `tracking_events.payload` (rule 20). Drivers already hash user data and leave secrets
 * out; this additionally redacts the keys that are sent unhashed by design (Meta's
 * `client_ip_address` / `client_user_agent`, raw click ids) and any credential-shaped key,
 * so the diagnostics table holds no personal data and no secret even if a driver slips.
 */
final class SentPayloadScrubber
{
    public const string REDACTED = '[redacted]';

    /** Keys whose values are replaced wherever they appear (case-insensitive). */
    private const array REDACT = [
        'client_ip_address', 'client_user_agent', 'ip', 'ip_override', 'user_agent',
        'fbp', 'fbc', 'api_secret', 'access_token', 'test_event_code', 'appsecret_proof',
        'phone', 'email', 'address',
    ];

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function scrub(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT, true)) {
                $out[$key] = $value === null ? null : self::REDACTED;

                continue;
            }
            $out[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $out;
    }
}
