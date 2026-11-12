<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** An order that can no longer be edited (P4-ORD-07, see EditOrder::EDITABLE). 409 `conflict`. */
final class OrderNotEditable extends ConflictHttpException
{
    public static function inStatus(string $number, string $status): self
    {
        return new self("Order {$number} is {$status} and can no longer be edited.");
    }

    public static function moneyInFlight(string $number): self
    {
        return new self("Order {$number} has a payment in progress or paid; its total cannot change.");
    }

    public static function variantGone(string $number, string $sku): self
    {
        return new self("Order {$number} has a line ({$sku}) whose product variant no longer exists; it cannot be edited.");
    }
}
