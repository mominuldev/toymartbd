<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Services;

use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Exceptions\UnknownCourierProvider;
use App\Domain\Fulfilment\Models\CourierProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Provider code → driver (rule 5: a courier is added by writing a driver, never by
 * touching order code). Drivers are listed in `config/couriers.php` (`drivers`) and
 * registered by FulfilmentServiceProvider; they are resolved lazily from the container
 * so credentials are only decrypted when a call is actually made.
 *
 * Tests swap in an instance — usually {@see \App\Domain\Fulfilment\Drivers\NullCourierGateway}
 * — with `register($code, $instance)`.
 */
final class CourierRegistry
{
    /** @var array<string, class-string<CourierGateway>|CourierGateway> */
    private array $drivers = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<CourierGateway>|CourierGateway  $driver */
    public function register(string $code, string|CourierGateway $driver): void
    {
        $this->drivers[$code] = $driver;
    }

    public function supports(string $code): bool
    {
        return isset($this->drivers[$code]);
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->drivers);
    }

    public function for(string $code): CourierGateway
    {
        $driver = $this->drivers[$code] ?? throw UnknownCourierProvider::for($code);

        if ($driver instanceof CourierGateway) {
            return $driver;
        }

        /** @var CourierGateway */
        return $this->container->make($driver);
    }

    public function forProvider(CourierProvider $provider): CourierGateway
    {
        return $this->for($provider->code);
    }
}
