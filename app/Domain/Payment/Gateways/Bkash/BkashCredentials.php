<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Bkash;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Domain\Payment\Support\GatewayEnvFallback;
use SensitiveParameter;

/**
 * bKash Tokenized Checkout credentials for the CURRENT mode (rule 20): the username and
 * password bKash sends in the grant-token headers, the app key and secret in its body. The
 * `payment_gateways` row's `sandbox` flag picks the key set ({@see GatewayCredentials}); env
 * values fill the sandbox set only. A missing row means sandbox.
 *
 * Live mode also needs the live base URL, which bKash does not publish (it is supplied at
 * onboarding — config payments.bkash.hosts.live). Without it the live set resolves to null:
 * not configured, so no payment can be taken. Never logged: `__debugInfo` hides every secret.
 */
final readonly class BkashCredentials
{
    public function __construct(
        #[SensitiveParameter] public string $username,
        #[SensitiveParameter] public string $password,
        #[SensitiveParameter] public string $appKey,
        #[SensitiveParameter] public string $appSecret,
        public bool $sandbox,
        public string $baseUrl,
    ) {}

    public static function resolve(): ?self
    {
        $row = PaymentProvider::forCode(PaymentMethod::Bkash->value);
        $sandbox = self::sandboxMode($row);
        $set = GatewayCredentials::forMode($row, $sandbox, GatewayEnvFallback::for(PaymentMethod::Bkash));
        $base = self::baseUrl($sandbox);

        if ($base === null || ! GatewayCredentials::complete(PaymentMethod::Bkash, $set)) {
            return null;
        }

        return new self($set['username'], $set['password'], $set['app_key'], $set['app_secret'], $sandbox, $base);
    }

    public static function sandboxMode(?PaymentProvider $row = null): bool
    {
        $row ??= PaymentProvider::forCode(PaymentMethod::Bkash->value);

        return $row === null || $row->sandbox;
    }

    /** The mode's base URL, https only; null when not configured (live is unpublished). */
    public static function baseUrl(bool $sandbox): ?string
    {
        $url = config('payments.bkash.hosts.'.GatewayCredentials::mode($sandbox));

        return is_string($url) && str_starts_with($url, 'https://') ? rtrim($url, '/') : null;
    }

    /**
     * Identifies this key set for the token cache without holding a secret in the key: a
     * hash of mode, host, username and app key. New keys → a new token.
     */
    public function cacheId(): string
    {
        return hash('sha256', implode("\0", [GatewayCredentials::mode($this->sandbox), $this->baseUrl, $this->username, $this->appKey]));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'username' => '[redacted]',
            'password' => '[redacted]',
            'appKey' => '[redacted]',
            'appSecret' => '[redacted]',
            'sandbox' => $this->sandbox,
            'baseUrl' => $this->baseUrl,
        ];
    }
}
