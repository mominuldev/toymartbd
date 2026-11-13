<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `inventory_alerts.kind`.
 *
 *   low_stock      0 < available ≤ reorder point (the overview's Low rule)
 *   out_of_stock   available ≤ 0 where a reorder point is set, or at the default location
 *   ledger_drift   SUM(stock_movements.quantity) ≠ stock_levels.on_hand (invariant §7.1)
 *   reserved_drift stock_levels.reserved ≠ the open-order quantity (invariant §7.5)
 *
 * Stock alerts open and resolve themselves as the level moves. Drift alerts are opened
 * by the nightly reconciliation and closed only by a person (acknowledge): drift means
 * a code path bypassed a transaction, and nothing corrects it silently.
 */
enum InventoryAlertKind: string
{
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';
    case LedgerDrift = 'ledger_drift';
    case ReservedDrift = 'reserved_drift';

    public function isDrift(): bool
    {
        return $this === self::LedgerDrift || $this === self::ReservedDrift;
    }

    /** @return list<self> */
    public static function stockKinds(): array
    {
        return [self::LowStock, self::OutOfStock];
    }

    /** @return list<self> */
    public static function driftKinds(): array
    {
        return [self::LedgerDrift, self::ReservedDrift];
    }
}
