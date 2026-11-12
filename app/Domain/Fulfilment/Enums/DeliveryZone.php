<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Enums;

/**
 * Our delivery-charge tier (DATABASE-DESIGN §5.1). Says nothing about a courier's own
 * zone ids, which live in courier_locations and are never guessed (rule 32).
 */
enum DeliveryZone: string
{
    case InsideDhakaMetro = 'inside_dhaka_metro';
    case DhakaSuburb = 'dhaka_suburb';
    case OutsideDhaka = 'outside_dhaka';
}
