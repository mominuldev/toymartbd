<?php

declare(strict_types=1);

namespace App\Domain\Payment\Support;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;

/**
 * One shape for every online gateway's stored keys (owner request 2026-11-19: each gateway
 * runs in sandbox OR live mode, switched in the admin). `payment_gateways.credentials`
 * (encrypted, rule 20) holds one key set per mode:
 *
 *     {"sandbox": {"store_id": "…", "store_password": "…"}, "live": {…}}
 *
 * The row's `sandbox` flag picks the set in use. Env values are a fallback for the SANDBOX
 * set only — a live key always comes from the owner's saved settings. A legacy flat set
 * (keys at the top level, written before the split) is read as the sandbox set.
 */
final class GatewayCredentials
{
    public const string SANDBOX = 'sandbox';

    public const string LIVE = 'live';

    /**
     * The fields each gateway needs, in display order. `secret` fields are never returned
     * by the API — only whether they are set, and, where `hint` is true, at most their last
     * four characters ("••••1234", like the courier and messaging keys) so the owner can tell
     * which account is connected.
     *
     * bKash: all four credentials bKash issues are secret (the username and password go in
     * the grant-token headers, the app key and secret in its body — docs/_research/bkash-api.md).
     *
     * @return list<array{key: string, secret: bool, hint: bool}>
     */
    public static function fields(PaymentMethod $method): array
    {
        return match ($method) {
            PaymentMethod::Sslcommerz => [
                ['key' => 'store_id', 'secret' => false, 'hint' => false],
                ['key' => 'store_password', 'secret' => true, 'hint' => false],
            ],
            PaymentMethod::Paystation => [
                ['key' => 'merchant_id', 'secret' => false, 'hint' => false],
                ['key' => 'password', 'secret' => true, 'hint' => false],
            ],
            PaymentMethod::Bkash => [
                ['key' => 'username', 'secret' => true, 'hint' => true],
                ['key' => 'password', 'secret' => true, 'hint' => true],
                ['key' => 'app_key', 'secret' => true, 'hint' => true],
                ['key' => 'app_secret', 'secret' => true, 'hint' => true],
            ],
            default => [],
        };
    }

    /** @return list<PaymentMethod> gateways whose keys the admin manages */
    public static function methods(): array
    {
        return [PaymentMethod::Sslcommerz, PaymentMethod::Paystation, PaymentMethod::Bkash];
    }

    /** "••••1234": at most the last four characters, and none of a value shorter than eight. */
    public static function hint(?string $value): ?string
    {
        if ($value === null || mb_strlen($value) < 8) {
            return $value === null ? null : '••••';
        }

        return '••••'.mb_substr($value, -4);
    }

    public static function mode(bool $sandbox): string
    {
        return $sandbox ? self::SANDBOX : self::LIVE;
    }

    /**
     * The stored key set for one mode, trimmed, blanks dropped; env fills sandbox gaps.
     *
     * @param  array<string, string|null>  $envFallback  field key => env/config value (sandbox only)
     * @return array<string, string>
     */
    public static function forMode(?PaymentProvider $row, bool $sandbox, array $envFallback = []): array
    {
        $stored = $row === null ? [] : ($row->credentials ?? []);
        $set = $stored[self::mode($sandbox)] ?? null;
        if (! is_array($set) && $sandbox && ! isset($stored[self::LIVE])) {
            // Legacy flat shape → the sandbox set.
            $set = array_filter($stored, fn (mixed $v): bool => ! is_array($v), ARRAY_FILTER_USE_BOTH);
        }
        $set = is_array($set) ? $set : [];

        $out = [];
        foreach ($set as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '') {
                $out[$key] = trim($value);
            }
        }
        if ($sandbox) {
            foreach ($envFallback as $key => $value) {
                if (! isset($out[$key]) && is_string($value) && trim($value) !== '') {
                    $out[$key] = trim($value);
                }
            }
        }

        return $out;
    }

    /**
     * Every field the gateway needs is present in this set.
     *
     * @param  array<string, string>  $set
     */
    public static function complete(PaymentMethod $method, array $set): bool
    {
        foreach (self::fields($method) as $field) {
            if (! isset($set[$field['key']])) {
                return false;
            }
        }

        return self::fields($method) !== [];
    }
}
