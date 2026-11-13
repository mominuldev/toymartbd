<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** No usable courier provider for a shipment request — rendered as a 422. */
final class CourierUnavailable extends UnprocessableEntityHttpException
{
    public static function noDefault(): self
    {
        return new self('No active courier provider is configured.');
    }

    public static function inactive(string $code): self
    {
        return new self("Courier provider [{$code}] is not active.");
    }

    public static function unknown(string $code): self
    {
        return new self("Courier provider [{$code}] does not exist or has no driver.");
    }

    public static function locationNotForProvider(): self
    {
        return new self('The chosen courier location does not belong to this provider.');
    }
}
