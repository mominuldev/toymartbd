<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Exceptions\UnsupportedPaymentMethod;
use Illuminate\Contracts\Container\Container;

/**
 * Method → driver (rule 5). Drivers are registered once, in AppServiceProvider; a new
 * gateway (SSLCommerz, bKash…) is a new driver class plus one `register()` line.
 * Drivers are resolved lazily from the container so credentials are only read when used.
 */
final class PaymentGatewayRegistry
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $drivers = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<PaymentGateway>  $driver */
    public function register(PaymentMethod $method, string $driver): void
    {
        $this->drivers[$method->value] = $driver;
    }

    public function supports(PaymentMethod $method): bool
    {
        return isset($this->drivers[$method->value]);
    }

    /** @return list<PaymentMethod> */
    public function methods(): array
    {
        return array_map(PaymentMethod::from(...), array_keys($this->drivers));
    }

    public function for(PaymentMethod $method): PaymentGateway
    {
        $driver = $this->drivers[$method->value] ?? throw UnsupportedPaymentMethod::for($method);

        /** @var PaymentGateway */
        return $this->container->make($driver);
    }
}
