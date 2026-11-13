<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A rider on a shipment no courier holds (pending, failed, cancelled) — a 409 `conflict`. */
final class RiderNotRecordable extends ConflictHttpException
{
    public static function for(string $number, ShipmentStatus $status): self
    {
        return new self("Shipment {$number} is {$status->value}: no courier holds it, so it has no rider.");
    }
}
