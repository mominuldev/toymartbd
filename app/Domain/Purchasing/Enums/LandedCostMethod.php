<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Enums;

/**
 * How a receipt's landed costs (freight, customs/duty, other) are split across its
 * lines. `value` is the default and what DATABASE-DESIGN §3.2 specifies; `quantity`
 * suits a shipment of similar items, `weight` a freight bill charged by the kilo
 * (needs every line's `product_variants.weight_grams`).
 */
enum LandedCostMethod: string
{
    case Value = 'value';
    case Quantity = 'quantity';
    case Weight = 'weight';
}
