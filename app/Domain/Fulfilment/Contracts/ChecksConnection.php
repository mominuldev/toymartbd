<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

use App\Domain\Fulfilment\DTOs\ConnectionCheck;

/**
 * A courier driver that can prove its saved credentials work with one harmless, read-only
 * call (Settings › Couriers › "Check connection"). Nothing is booked or changed at the courier.
 * It makes an external call: never inside a database transaction (rule 4).
 */
interface ChecksConnection
{
    public function checkConnection(): ConnectionCheck;
}
