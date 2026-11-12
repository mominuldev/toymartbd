<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use App\Domain\Payment\Enums\PaymentMethod;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** No driver is registered for this method (rule 5: a gateway is added by writing a driver). */
final class UnsupportedPaymentMethod extends UnprocessableEntityHttpException
{
    public static function for(PaymentMethod $method): self
    {
        return new self("No payment driver is registered for [{$method->value}].");
    }
}
