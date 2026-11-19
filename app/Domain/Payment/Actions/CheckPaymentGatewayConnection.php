<?php

declare(strict_types=1);

namespace App\Domain\Payment\Actions;

use App\Domain\Payment\Contracts\ChecksConnection;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Domain\Payment\Support\GatewayCredentials;

/**
 * Integrations › Payments › "Check connection": asks the gateway, with the keys saved for its
 * CURRENT mode, one read-only question ({@see ChecksConnection}). The external call runs
 * outside any transaction (rule 4); nothing is written — not `verified`, not the mode.
 */
final readonly class CheckPaymentGatewayConnection
{
    public function __construct(private PaymentGatewayRegistry $registry) {}

    /**
     * @return array{ok: bool, code: string, mode: string}|null null when the gateway has no
     *                                                          driver that can check its keys
     */
    public function handle(PaymentMethod $method): ?array
    {
        if (! $this->registry->supports($method)) {
            return null;
        }
        $driver = $this->registry->for($method);
        if (! $driver instanceof ChecksConnection) {
            return null;
        }

        $row = PaymentProvider::forCode($method->value);
        $result = $driver->checkConnection();

        return ['ok' => $result['ok'], 'code' => $result['code'], 'mode' => GatewayCredentials::mode($row === null || $row->sandbox)];
    }
}
