<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use App\Domain\Payment\Contracts\ChecksConfiguration;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\DTOs\GatewayResult;
use App\Domain\Payment\DTOs\PaymentContext;
use App\Domain\Payment\DTOs\RefundResult;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Enums\RefundStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentProvider;
use App\Domain\Payment\Services\PaymentGatewayRegistry;
use App\Domain\Payment\Support\GatewayCredentials;
use App\Support\Money;
use LogicException;

/**
 * A driver that behaves like a real admin-managed gateway on the switchboard only: the
 * row's `sandbox` flag picks the mode, and it is configured when that mode's key set is
 * complete per {@see GatewayCredentials}. Lets hub / CLI / launch tests register SSLCommerz
 * or PayStation without depending on the real driver's internals. Never talks to anyone.
 */
abstract class ModeAwareGatewayFake implements ChecksConfiguration, PaymentGateway
{
    abstract public function method(): PaymentMethod;

    /** Register this fake as the driver for its method (replacing any real one). */
    public function register(): void
    {
        app(PaymentGatewayRegistry::class)->register($this->method(), static::class);
    }

    public function isSandbox(): bool
    {
        $row = PaymentProvider::forCode($this->method()->value);

        return $row === null || $row->sandbox;
    }

    public function isConfigured(): bool
    {
        $row = PaymentProvider::forCode($this->method()->value);

        return GatewayCredentials::complete($this->method(), GatewayCredentials::forMode($row, $this->isSandbox()));
    }

    public function initiate(Payment $payment, PaymentContext $context): GatewayResult
    {
        throw new LogicException('Switchboard fake: no payments.');
    }

    public function verify(Payment $payment): GatewayResult
    {
        return new GatewayResult(PaymentStatus::Initiated);
    }

    public function refund(Payment $payment, Money $amount, string $reason): RefundResult
    {
        return new RefundResult(RefundStatus::Failed);
    }
}
