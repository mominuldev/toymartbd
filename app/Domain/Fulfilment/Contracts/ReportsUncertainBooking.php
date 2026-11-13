<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

/**
 * OPTIONAL capability of an exception thrown by {@see CourierGateway::book()} or
 * `bookMany()`: whether the provider may have created the consignment despite the error —
 * e.g. a 2xx whose body could not be read (Pathao `malformed`). When it returns true the
 * booking job NEVER re-posts (double-booking risk): the shipment goes to `submitted` and is
 * resolved by `statusByMerchantRef()` (if the driver has it), the webhook or the poll.
 */
interface ReportsUncertainBooking
{
    public function bookingMayHaveSucceeded(): bool;
}
