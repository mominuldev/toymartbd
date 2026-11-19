<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Paystation;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Domain\Payment\Support\GatewayEnvFallback;
use SensitiveParameter;

/**
 * PayStation merchant credentials for the CURRENT mode (rule 20). The `payment_gateways`
 * row's `sandbox` flag picks the key set ({@see GatewayCredentials}); env values fill the
 * sandbox set only. A missing row means sandbox — a half-configured switch stays safe.
 * Never logged: `__debugInfo` hides the password.
 */
final readonly class PaystationCredentials
{
    public function __construct(
        public string $merchantId,
        #[SensitiveParameter] public string $password,
        public bool $sandbox,
    ) {}

    public static function resolve(): ?self
    {
        $row = PaymentProvider::forCode(PaymentMethod::Paystation->value);
        $sandbox = self::sandboxMode($row);
        $set = GatewayCredentials::forMode($row, $sandbox, GatewayEnvFallback::for(PaymentMethod::Paystation));

        if (! GatewayCredentials::complete(PaymentMethod::Paystation, $set)) {
            return null;
        }

        return new self($set['merchant_id'], $set['password'], $sandbox);
    }

    public static function sandboxMode(?PaymentProvider $row = null): bool
    {
        $row ??= PaymentProvider::forCode(PaymentMethod::Paystation->value);

        return $row === null || $row->sandbox;
    }

    public function host(): string
    {
        return rtrim((string) config('payments.paystation.hosts.'.GatewayCredentials::mode($this->sandbox)), '/');
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['merchantId' => $this->merchantId, 'password' => '[redacted]', 'sandbox' => $this->sandbox];
    }
}
