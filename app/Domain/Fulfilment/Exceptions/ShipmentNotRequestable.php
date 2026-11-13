<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The order cannot get a (new) shipment now — rendered as a 409 `conflict`. */
final class ShipmentNotRequestable extends ConflictHttpException
{
    public bool $orderMissing = false;

    public static function orderNotShippable(string $number, string $status): self
    {
        return new self("Order {$number} cannot be shipped from status {$status}.");
    }

    public static function openShipmentExists(string $number, string $shipmentNumber): self
    {
        return new self("Order {$number} already has an open shipment ({$shipmentNumber}).");
    }

    public static function orderMissing(int $orderId): self
    {
        $e = new self("Order {$orderId} was not found.");
        $e->orderMissing = true;

        return $e;
    }
}
