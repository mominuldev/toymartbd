<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Support;

use App\Domain\Fulfilment\Models\CourierProvider;

/**
 * Which credential keys each courier's OFFICIAL merchant API needs, stored encrypted in
 * `courier_providers.credentials` (rule 20). Drivers read these exact keys.
 *
 * - Pathao Merchant API (aladdin): an OAuth password grant — `client_id`, `client_secret`
 *   plus the merchant `username` (email) and `password` — issues the access token, and
 *   `store_id` names the pickup store. Username/password here is the published API's own
 *   token flow, not a portal login used to scrape undocumented endpoints (rule 24a forbids
 *   only the latter; SYSTEM-ARCHITECTURE §8).
 * - Steadfast (packzy portal API): `api_key` and `secret_key` headers.
 *
 * Unknown codes accept no credential keys, so a new provider must be added here with its
 * driver (rule 5). Values never leave the server: the API returns `set` + a hint of at
 * most the last four characters.
 */
final class CourierCredentialFields
{
    /** @var array<string, list<string>> */
    public const array FIELDS = [
        'pathao' => ['client_id', 'client_secret', 'username', 'password', 'store_id'],
        'steadfast' => ['api_key', 'secret_key'],
    ];

    /** Keys whose value is a secret in its own right (a hint is still at most the last four). */
    public const array SECRET = ['client_secret', 'password', 'api_key', 'secret_key'];

    /** @return list<string> */
    public static function for(CourierProvider $provider): array
    {
        return self::FIELDS[$provider->code] ?? [];
    }

    /** Every required credential is present — the provider can at least attempt a call. */
    public static function complete(CourierProvider $provider): bool
    {
        $fields = self::for($provider);
        if ($fields === []) {
            return false;
        }
        $saved = $provider->credentials ?? [];
        foreach ($fields as $field) {
            if (! self::filled($saved[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Whether any credential at all is stored. */
    public static function any(CourierProvider $provider): bool
    {
        foreach ($provider->credentials ?? [] as $value) {
            if (self::filled($value)) {
                return true;
            }
        }

        return false;
    }

    /** "••••1234" for a value of 8+ characters, "••••" for a shorter one, null when unset. */
    public static function hint(mixed $value): ?string
    {
        if (! self::filled($value)) {
            return null;
        }
        $value = (string) $value;

        return mb_strlen($value) >= 8 ? '••••'.mb_substr($value, -4) : '••••';
    }

    public static function filled(mixed $value): bool
    {
        return (is_string($value) && trim($value) !== '') || is_int($value);
    }
}
