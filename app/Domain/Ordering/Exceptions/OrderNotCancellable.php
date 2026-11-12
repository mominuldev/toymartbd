<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Only a pre-shipment order can be cancelled; after dispatch it is a return. 409 `conflict`. */
final class OrderNotCancellable extends ConflictHttpException
{
    public static function inStatus(string $number, string $status): self
    {
        return new self("Order {$number} is {$status} and can no longer be cancelled; record a return instead.");
    }
}
