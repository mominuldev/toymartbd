<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Drivers\Pathao;

use App\Domain\Fulfilment\Models\CourierProvider;
use SensitiveParameter;

/**
 * Pathao merchant credentials (rule 20). Read from the `courier_providers` row's encrypted
 * `credentials` (`client_id`, `client_secret`, `username`, `password`, `store_id`) and
 * `webhook_secret`; env (`PATHAO_*`) is a fallback in SANDBOX mode only, so a production
 * install can never silently run on a developer's env values.
 *
 * Sandbox unless BOTH the row (`settings.environment = production`) and env
 * (`PATHAO_SANDBOX=false`) say live — a half-configured switch stays safe.
 * Never logged: `__debugInfo` redacts every secret.
 */
final readonly class PathaoCredentials
{
    public function __construct(
        public string $clientId,
        #[SensitiveParameter] public string $clientSecret,
        public string $username,
        #[SensitiveParameter] public string $password,
        public ?int $storeId,
        #[SensitiveParameter] public ?string $webhookSecret,
        public bool $sandbox,
    ) {}

    public static function resolve(?CourierProvider $row = null): ?self
    {
        $row ??= CourierProvider::query()->where('code', PathaoGateway::CODE)->first();
        $sandbox = self::sandboxMode($row);
        $stored = $row === null ? [] : ($row->credentials ?? []);
        // One source, never a mix: the row once it holds a client id, else (sandbox only) env.
        $source = self::string($stored['client_id'] ?? null) !== null ? $stored : ($sandbox ? (array) config('couriers.pathao') : []);

        $pick = static fn (string $key): ?string => self::string($source[$key] ?? null);

        $clientId = $pick('client_id');
        $clientSecret = $pick('client_secret');
        $username = $pick('username');
        $password = $pick('password');
        if ($clientId === null || $clientSecret === null || $username === null || $password === null) {
            return null;
        }

        $storeId = $pick('store_id');
        $webhookSecret = self::string($row?->webhook_secret) ?? ($sandbox ? self::string(config('couriers.pathao.webhook_secret')) : null);

        return new self(
            $clientId,
            $clientSecret,
            $username,
            $password,
            $storeId !== null && ctype_digit($storeId) ? (int) $storeId : null,
            $webhookSecret,
            $sandbox,
        );
    }

    public static function sandboxMode(?CourierProvider $row): bool
    {
        $rowSaysLive = ($row?->settings['environment'] ?? null) === 'production';

        return ! $rowSaysLive || (bool) config('couriers.pathao.sandbox', true);
    }

    public function host(): string
    {
        return rtrim((string) config('couriers.pathao.hosts.'.($this->sandbox ? 'sandbox' : 'live')), '/');
    }

    /** Distinguishes cached tokens per host and account without putting a secret in the key. */
    public function cacheKey(): string
    {
        return 'couriers:pathao:token:'.hash('sha256', $this->host().'|'.$this->clientId.'|'.$this->username);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'clientSecret' => '[redacted]',
            'username' => '[redacted]',
            'password' => '[redacted]',
            'storeId' => $this->storeId,
            'webhookSecret' => '[redacted]',
            'sandbox' => $this->sandbox,
        ];
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
