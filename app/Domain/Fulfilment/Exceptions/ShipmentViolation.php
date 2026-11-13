<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Exceptions;

use LogicException;

/** A programming error against shipment invariants — never a staff-facing condition. */
final class ShipmentViolation extends LogicException
{
    public static function neverDeleted(): self
    {
        return new self('A shipment is fulfilment and money history and is never deleted.');
    }

    public static function outsideTransaction(): self
    {
        return new self('Shipment writes must run inside the calling Action\'s DB::transaction (rule 3).');
    }
}
