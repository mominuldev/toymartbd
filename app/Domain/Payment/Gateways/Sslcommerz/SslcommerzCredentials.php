<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Sslcommerz;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Domain\Payment\Support\GatewayEnvFallback;
use SensitiveParameter;

/**
 * SSLCommerz store credentials (rule 20) for the gateway's CURRENT mode. The
 * `payment_gateways` row's `sandbox` flag — switched by the owner in Integrations › Payments —
 * picks the key set in its encrypted `credentials` ({@see GatewayCredentials}); env values
 * fill gaps in the sandbox set only, never the live one. Production still refuses an
 * unverified gateway ({@see \App\Domain\Payment\Services\OnlineGateways}). Never logged:
 * `__debugInfo` hides the password and the class is not serialised into payloads.
 */
final readonly class SslcommerzCredentials
{
    public function __construct(
        public string $storeId,
        #[SensitiveParameter] public string $storePassword,
        public bool $sandbox,
    ) {}

    public static function resolve(): ?self
    {
        $row = PaymentProvider::forCode(PaymentMethod::Sslcommerz->value);
        $sandbox = self::sandboxMode($row);
        $set = GatewayCredentials::forMode($row, $sandbox, GatewayEnvFallback::for(PaymentMethod::Sslcommerz));

        $storeId = $set['store_id'] ?? null;
        $password = $set['store_password'] ?? null;
        if ($storeId === null || $password === null) {
            return null;
        }

        return new self($storeId, $password, $sandbox);
    }

    /** The row's mode; no row means sandbox. */
    public static function sandboxMode(?PaymentProvider $row = null): bool
    {
        $row ??= PaymentProvider::forCode(PaymentMethod::Sslcommerz->value);

        return $row === null || $row->sandbox;
    }

    public function host(): string
    {
        return rtrim((string) config('payments.sslcommerz.hosts.'.($this->sandbox ? 'sandbox' : 'live')), '/');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['storeId' => $this->storeId, 'storePassword' => '[redacted]', 'sandbox' => $this->sandbox];
    }
}
