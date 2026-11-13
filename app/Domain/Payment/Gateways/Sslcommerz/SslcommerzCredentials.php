<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Sslcommerz;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use SensitiveParameter;

/**
 * SSLCommerz store credentials (rule 20). Resolved from the `payment_gateways` row's
 * encrypted `credentials` when an owner has stored them, else from env (sandbox during
 * development). Never logged: `__debugInfo` hides the password and the class is not
 * serialised into payloads.
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
        $stored = $row === null ? [] : ($row->credentials ?? []);

        $storeId = self::string($stored['store_id'] ?? null) ?? self::string(config('payments.sslcommerz.store_id'));
        $password = self::string($stored['store_password'] ?? null) ?? self::string(config('payments.sslcommerz.store_password'));
        if ($storeId === null || $password === null) {
            return null;
        }

        return new self($storeId, $password, self::sandboxMode($row));
    }

    /** Sandbox unless BOTH the row and env say live — a half-configured switch stays safe. */
    public static function sandboxMode(?PaymentProvider $row = null): bool
    {
        $row ??= PaymentProvider::forCode(PaymentMethod::Sslcommerz->value);

        return ($row === null || $row->sandbox) || (bool) config('payments.sslcommerz.sandbox', true);
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

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
