<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** No driver is registered for this provider code (rule 5: a courier is added by writing a driver). */
final class UnknownCourierProvider extends UnprocessableEntityHttpException
{
    public static function for(string $code): self
    {
        return new self("No courier driver is registered for [{$code}].");
    }
}
