<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `stock_locations.type` — what a stock location is for (Store settings › Branches &
 * warehouses, Figma B · 38 241:50827). Null on a location nobody has classified yet.
 *
 *  - warehouse — online fulfilment: packs and hands parcels to the courier;
 *  - retail    — a shop: POS sales and click & collect;
 *  - pickup    — a partner pickup point: customers collect orders, nothing is sold there.
 */
enum StockLocationType: string
{
    case Warehouse = 'warehouse';
    case Retail = 'retail';
    case Pickup = 'pickup';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }
}
