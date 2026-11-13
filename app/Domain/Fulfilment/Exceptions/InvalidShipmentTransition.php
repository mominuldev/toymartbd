<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A shipment move the ShipmentStatus table refuses — rendered as a 409 `conflict`. */
final class InvalidShipmentTransition extends ConflictHttpException
{
    public static function between(ShipmentStatus $from, ShipmentStatus $to): self
    {
        return new self("A shipment cannot move from {$from->value} to {$to->value}.");
    }
}
