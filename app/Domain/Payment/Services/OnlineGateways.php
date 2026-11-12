<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Contracts\ChecksConfiguration;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Models\PaymentProvider;

/**
 * Which online methods may take money right now (P4-PAY-02). An online method is enabled
 * only when ALL of these hold:
 *
 *   - a driver is registered for it (rule 5);
 *   - its `payment_gateways` row is active;
 *   - the driver has credentials (env sandbox credentials, or stored encrypted ones);
 *   - in production: the row is `verified` AND not in sandbox mode — the payment
 *     counterpart of rule 33a (no production booking through an unverified courier).
 *
 * COD is always available and is the default (rule 33); it is not listed here.
 */
final readonly class OnlineGateways
{
    public function __construct(private PaymentGatewayRegistry $registry) {}

    public function isEnabled(PaymentMethod $method): bool
    {
        return $this->status($method)['enabled'];
    }

    /** @return list<PaymentMethod> */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->registry->methods(),
            fn (PaymentMethod $m): bool => $m->isOnline() && $this->isEnabled($m),
        ));
    }

    /**
     * @return array{method: string, registered: bool, active: bool, verified: bool, sandbox: bool, configured: bool, enabled: bool, reason: string|null}
     */
    public function status(PaymentMethod $method): array
    {
        $registered = $method->isOnline() && $this->registry->supports($method);
        $row = $registered ? PaymentProvider::forCode($method->value) : null;
        $driver = $registered ? $this->registry->for($method) : null;
        $configured = $driver instanceof ChecksConfiguration && $driver->isConfigured();
        $active = $row !== null && $row->is_active;
        $verified = $row !== null && $row->verified;
        $sandbox = ! $driver instanceof ChecksConfiguration || $driver->isSandbox();
        $production = app()->environment('production');

        $reason = match (true) {
            ! $registered => 'not_registered',
            ! $active => 'inactive',
            ! $configured => 'not_configured',
            $production && ! $verified => 'unverified',
            $production && $sandbox => 'sandbox_in_production',
            default => null,
        };

        return [
            'method' => $method->value,
            'registered' => $registered,
            'active' => $active,
            'verified' => $verified,
            'sandbox' => $sandbox,
            'configured' => $configured,
            'enabled' => $reason === null,
            'reason' => $reason,
        ];
    }
}
