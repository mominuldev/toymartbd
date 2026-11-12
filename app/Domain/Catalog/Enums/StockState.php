<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * What the storefront says about a variant's stock (StockIndicator, storefront spec
 * §A13). Decided here from the available count, never in the client (rule 1).
 * Pre-order and back-in-stock-soon need dates the schema does not hold yet.
 */
enum StockState: string
{
    case InStock = 'in_stock';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';

    /** "Only N left" shows the exact count at or below this (§A13). */
    public const int LOW_STOCK_THRESHOLD = 5;

    public static function fromAvailable(int $available): self
    {
        return match (true) {
            $available <= 0 => self::OutOfStock,
            $available <= self::LOW_STOCK_THRESHOLD => self::LowStock,
            default => self::InStock,
        };
    }
}
