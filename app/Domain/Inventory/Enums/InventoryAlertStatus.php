<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * `inventory_alerts.status`: open until the stock recovers (stock alerts, `resolved`) or
 * a person acknowledges it (`acknowledged`, the only way a drift alert closes).
 */
enum InventoryAlertStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Acknowledged = 'acknowledged';
}
