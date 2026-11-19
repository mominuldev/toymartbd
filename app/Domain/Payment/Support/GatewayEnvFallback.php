<?php

declare(strict_types=1);

namespace App\Domain\Payment\Support;

use App\Domain\Payment\Enums\PaymentMethod;

/**
 * The env/config values a gateway's SANDBOX key set falls back to while a field is not saved
 * in the admin: `payments.{code}.{field key}` for every field in
 * {@see GatewayCredentials::fields()} — e.g. `payments.sslcommerz.store_id`. A live key never
 * comes from here ({@see GatewayCredentials::forMode()} applies the fallback to sandbox only).
 */
final class GatewayEnvFallback
{
    /** @return array<string, string|null> field key => config value */
    public static function for(PaymentMethod $method): array
    {
        $out = [];
        foreach (GatewayCredentials::fields($method) as $field) {
            $value = config('payments.'.$method->value.'.'.$field['key']);
            $out[$field['key']] = is_string($value) ? $value : null;
        }

        return $out;
    }
}
